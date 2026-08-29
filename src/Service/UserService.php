<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Repository\UserRepository;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Contracts\Cache\CacheInterface;

class UserService
{
    public function __construct(
        private UserRepository $userRepository,
        private UserPasswordHasherInterface $passwordHasher,
        private TokenService $tokenService,
        private EntityManagerInterface $entityManager,
        private BasketService $basketService,
        private UniqUidGenerator $uniqUidGenerator,
        private CacheInterface $cacheInterface,
    ) {
    }

    public function createUser(User $user): User
    {
        $hashedPassword = $this->passwordHasher->hashPassword($user, $user->getPassword());
        $user->setPassword($hashedPassword);
        $token = $this->tokenService->generateToken();
        $user->setRegistrationToken($token);
        $user->setRegistrationTokenCreatedAt(new DateTime());
        $this->entityManager->persist($user);
        $this->entityManager->flush();
        $this->basketService->createBasketForUser($user);

        return $user;
    }

    public function generateChangePasswordToken(User $user): string
    {
        $token = $this->uniqUidGenerator->generateUniqueTokenForUser();
        $hashedToken = hash('sha256', $token);

        $userEmail = $user->getEmail();

        $this->cacheInterface->get($hashedToken, static function () use ($userEmail) {
            return $userEmail;
        }, 900); // store for 15 minutes

        return $token;
    }

    public function validateChangePasswordToken(string $token): bool
    {
        $userEmail = $this->getEmailFromCachedToken($token);
        if (!$userEmail) {
            return false;
        }
        $user = $this->userRepository->findOneBy(['email' => $userEmail]);
        if (!$user) {
            return false;
        }

        return true;
    }

    public function getEmailFromCachedToken(string $token): ?string
    {
        $hashedToken = hash('sha256', $token);

        return $this->cacheInterface->get($hashedToken, static function () {
            return null;
        });
    }

    public function removeChangePasswordToken(string $token): void
    {
        $hashedToken = hash('sha256', $token);
        $this->cacheInterface->delete($hashedToken);
    }
}