<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\EmailQueueService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function sprintf;

#[AsCommand(
    name: 'app:process-email-queue',
    description: 'Process pending emails from the queue',
)]
class ProcessEmailQueueCommand extends Command
{
    public function __construct(
        private EmailQueueService $emailQueueService,
        private string $environment,
        private int $batchSize = 10,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
          ->addOption('batch-size', 'b', InputOption::VALUE_REQUIRED, 'Number of emails to process', $this->batchSize)
          ->addOption('cleanup', null, InputOption::VALUE_NONE, 'Cleanup old emails')
          ->addOption('cleanup-days', null, InputOption::VALUE_REQUIRED, 'Days to keep emails', 30)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ('prod' === $this->environment) {
            $io->note('Running in production mode');
        }

        $io->title('Processing Email Queue');

        // Process emails
        $results = $this->emailQueueService->processBatch($this->batchSize);

        $io->table(
            ['Status', 'Count'],
            [
                ['Sent', $results['sent']],
                ['Failed', $results['failed']],
                ['Skipped', $results['skipped']],
            ],
        );

        // Optional cleanup
        if ($input->getOption('cleanup')) {
            $days = (int) $input->getOption('cleanup-days');
            $deleted = $this->emailQueueService->cleanupOldEmails($days);
            $io->success(sprintf('Cleaned up %d old email records', $deleted));
        }

        if ($results['failed'] > 0) {
            $io->warning('Some emails failed to send. Check logs for details.');

            return Command::FAILURE;
        }

        $io->success('Email queue processing completed');

        return Command::SUCCESS;
    }
}
