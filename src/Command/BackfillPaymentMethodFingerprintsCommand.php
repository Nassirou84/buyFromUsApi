<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\PaymentMethodService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function sprintf;

#[AsCommand(
    name: 'app:backfill-payment-method-fingerprints',
    description: 'Migrate legacy saved cards: set the blind-index fingerprint and re-encrypt the token bound to its owner (safe to re-run).',
)]
class BackfillPaymentMethodFingerprintsCommand extends Command
{
    public function __construct(
        private readonly PaymentMethodService $paymentMethodService,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $updated = $this->paymentMethodService->backfillMissingFingerprints();

        $io->success(sprintf('Backfilled %d payment method fingerprint(s).', $updated));

        return Command::SUCCESS;
    }
}
