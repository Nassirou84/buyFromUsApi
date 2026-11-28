<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Refund;
use App\Repository\RefundRepository;
use App\Service\RefundService;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

use function count;
use function sprintf;

/**
 * Safety net for missed or delayed Paystack refund webhooks: asks Paystack for the
 * current status of every refund that isn't final (pending, processing,
 * needs_attention) and records any change. Meant to run on a schedule (cron), e.g.
 *
 *   0,15,30,45 * * * * php bin/console app:refund:sync-status --no-interaction
 *
 * A Paystack error on one refund is reported and the others are still checked; the
 * command then exits with FAILURE so the scheduler can alert.
 */
#[AsCommand(
    name: 'app:refund:sync-status',
    description: 'Check pending refunds against Paystack and update their status',
)]
final class RefundStatusSyncCommand extends Command
{
    private const PROVIDER = 'paystack';

    public function __construct(
        private RefundRepository $refundRepository,
        private RefundService $refundService,
        private EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Maximum number of refunds to check (oldest first)')
            ->addOption('min-age', null, InputOption::VALUE_REQUIRED, 'Skip refunds created less than this many minutes ago (still being submitted)', '5')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would change without saving anything');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        $limit = $input->getOption('limit');
        $limit = null !== $limit ? (int) $limit : null;
        if (null !== $limit && $limit < 1) {
            $io->error('--limit must be a positive integer.');

            return Command::INVALID;
        }
        $minAge = (int) $input->getOption('min-age');
        if ($minAge < 0) {
            $io->error('--min-age must be zero or more minutes.');

            return Command::INVALID;
        }

        $createdBefore = new DateTimeImmutable(sprintf('-%d minutes', $minAge));
        $refunds = $this->refundRepository->findUnresolved(self::PROVIDER, $createdBefore, $limit);

        if ([] === $refunds) {
            $io->success('No pending refunds to check.');

            return Command::SUCCESS;
        }

        $io->text(sprintf('Checking %d refund(s) against Paystack%s...', count($refunds), $dryRun ? ' (dry run)' : ''));

        $counts = [
            RefundService::SYNC_UPDATED => 0,
            RefundService::SYNC_UNCHANGED => 0,
            RefundService::SYNC_NOT_FOUND => 0,
            RefundService::SYNC_UNKNOWN_STATUS => 0,
            'error' => 0,
        ];
        $rows = [];

        // Rows with no gateway id are matched by scanning GET /refund: do it once for the run.
        $earliestUnlinked = null;
        foreach ($refunds as $refund) {
            if (null === $refund->getRefundTransactionNumber()) {
                $earliestUnlinked = $refund->getCreatedAt();
                break; // oldest first
            }
        }
        if (null !== $earliestUnlinked) {
            $this->refundService->beginSyncRun($earliestUnlinked);
        }

        try {
            $this->syncAll($refunds, $dryRun, $io, $counts, $rows);
        } finally {
            $this->refundService->endSyncRun();
        }

        if ([] !== $rows) {
            $io->table(['Reference', 'Was', 'Now', 'Result'], $rows);
        }

        $summary = sprintf(
            '%d %s, %d unchanged, %d not found, %d unknown status, %d error(s).',
            $counts[RefundService::SYNC_UPDATED],
            $dryRun ? 'would update' : 'updated',
            $counts[RefundService::SYNC_UNCHANGED],
            $counts[RefundService::SYNC_NOT_FOUND],
            $counts[RefundService::SYNC_UNKNOWN_STATUS],
            $counts['error'],
        );

        if ($counts['error'] > 0) {
            $io->warning($summary);

            return Command::FAILURE;
        }

        $io->success($summary);

        return Command::SUCCESS;
    }

    /**
     * @param list<Refund>            $refunds
     * @param array<string, int>      $counts
     * @param list<list<string|null>> $rows
     */
    private function syncAll(array $refunds, bool $dryRun, SymfonyStyle $io, array &$counts, array &$rows): void
    {
        foreach ($refunds as $refund) {
            $previousStatus = $refund->getStatus();
            try {
                $result = $this->refundService->syncWithGateway($refund, $dryRun);
            } catch (Throwable $e) {
                ++$counts['error'];
                $rows[] = [$refund->getReference(), $previousStatus, '-', 'error: ' . $e->getMessage()];
                // A failed flush closes the entity manager; stop rather than cascade errors.
                if (!$this->entityManager->isOpen()) {
                    $io->error('The database connection was closed after an error; stopping.');
                    break;
                }
                continue;
            }

            ++$counts[$result['outcome']];
            if (RefundService::SYNC_UPDATED === $result['outcome']) {
                $rows[] = [
                    $refund->getReference(),
                    $previousStatus,
                    $result['status'],
                    ($dryRun ? 'would update' : 'updated') . ' (Paystack: ' . $result['gatewayStatus'] . ')',
                ];
            } elseif (RefundService::SYNC_NOT_FOUND === $result['outcome']) {
                $rows[] = [$refund->getReference(), $previousStatus, '-', 'not found on Paystack'];
            } elseif (RefundService::SYNC_UNKNOWN_STATUS === $result['outcome']) {
                $rows[] = [$refund->getReference(), $previousStatus, '-', 'unknown Paystack status: ' . $result['gatewayStatus']];
            }
        }
    }
}
