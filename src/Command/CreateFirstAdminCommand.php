<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

use function is_string;
use function sprintf;

use const FILTER_VALIDATE_EMAIL;

#[AsCommand(
    name: 'app:create-admin',
    description: 'Create the first super admin if none exists yet (safe to run on every deploy)',
)]
class CreateFirstAdminCommand extends Command
{
    private const MIN_PASSWORD_LENGTH = 12;

    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly EntityManagerInterface $entityManager,
        private readonly ?string $defaultEmail = null,
        private readonly ?string $defaultPassword = null,
        private readonly ?string $defaultFirstName = null,
        private readonly ?string $defaultLastName = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('email', null, InputOption::VALUE_REQUIRED, 'Admin email (default: FIRST_ADMIN_EMAIL)')
            ->addOption('password', null, InputOption::VALUE_REQUIRED, 'Admin password (default: FIRST_ADMIN_PASSWORD; prefer the env var, arguments show up in process lists)')
            ->addOption('first-name', null, InputOption::VALUE_REQUIRED, 'First name (default: FIRST_ADMIN_FIRST_NAME, then "Admin")')
            ->addOption('last-name', null, InputOption::VALUE_REQUIRED, 'Last name (default: FIRST_ADMIN_LAST_NAME, then "Admin")')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($this->userRepository->hasSuperAdmin()) {
            $io->writeln('A super admin already exists, nothing to do.');

            return Command::SUCCESS;
        }

        $email = $this->pick($input->getOption('email'), $this->defaultEmail);
        $password = $this->pick($input->getOption('password'), $this->defaultPassword);

        if (!$input->isInteractive()) {
            if (null === $email || null === $password) {
                // Non-interactive runs (deploys) must not fail just because the secrets are not configured.
                $io->warning('No super admin yet, but FIRST_ADMIN_EMAIL / FIRST_ADMIN_PASSWORD are not set: skipping.');

                return Command::SUCCESS;
            }
        } else {
            $email ??= $io->ask('Admin email');
            $password ??= $io->askHidden('Admin password (hidden)');
        }

        if (null === $email || false === filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $io->error('A valid email is required.');

            return Command::FAILURE;
        }
        if (null === $password || mb_strlen($password) < self::MIN_PASSWORD_LENGTH) {
            $io->error(sprintf('The password must be at least %d characters long.', self::MIN_PASSWORD_LENGTH));

            return Command::FAILURE;
        }
        if (null !== $this->userRepository->findOneBy(['email' => $email])) {
            $io->error('A user with this email already exists but is not a super admin. Promote it manually instead.');

            return Command::FAILURE;
        }

        $user = new User();
        $user->setEmail($email);
        $user->setFirstName($this->pick($input->getOption('first-name'), $this->defaultFirstName) ?? 'Admin');
        $user->setLastName($this->pick($input->getOption('last-name'), $this->defaultLastName) ?? 'Admin');
        $user->setRoles([User::ROLE_SUPER_ADMIN, User::ROLE_ADMIN]);
        $user->setIsGrantedAccess(true);
        $user->setPassword($this->passwordHasher->hashPassword($user, $password));

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $io->success(sprintf('Super admin %s created.', $email));

        return Command::SUCCESS;
    }

    private function pick(mixed ...$candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (is_string($candidate) && '' !== trim($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
