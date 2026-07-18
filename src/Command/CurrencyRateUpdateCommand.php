<?php

namespace App\Command;

use App\Service\ExchangeRateApiService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:currency-rate-update',
    description: 'Update currency rates',
)]
class CurrencyRateUpdateCommand extends Command
{
    public function __construct(
        private ExchangeRateApiService $exchangeRateApiService
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription('Update currency rates');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $this->exchangeRateApiService->updateRates();
        $io->success('Currency rates updated successfully.');
        return Command::SUCCESS;
    }
}