<?php

namespace App\Command;

use App\Service\SecretManagerService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:test-command',
    description: 'Add a short description for your command',
)]
class TestCommand
{
    private SecretManagerService $secretManagerService;

    public function __construct(
        SecretManagerService $secretManagerService
    ) {
        $this->secretManagerService = $secretManagerService;
        // You can inject services here if needed
    }

    public function __invoke(
        SymfonyStyle $io
    ): int {

        $secret = 'my_secret_value';
        $encrypted = $this->secretManagerService->encryptElement($secret);
        $decrypted = $this->secretManagerService->decryptElement($encrypted);

        $io->success("Original: $secret");
        $io->success("Encrypted: $encrypted");
        $io->success("Decrypted: $decrypted");

        return Command::SUCCESS;
    }
}