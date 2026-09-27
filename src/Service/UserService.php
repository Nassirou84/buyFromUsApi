<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Setting;
use App\Entity\User;
use App\Message\WelcomeMessage;
use App\Repository\UserRepository;
use App\Service\PromoCodeService;
use App\Entity\PromoCode;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
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
        private SettingService $settingService,
        private PromoCodeService $promoCodeService,
        private MessageBusInterface $messageBusInterface,
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

        $welcomeCodeSetting = $this->settingService->getSetting(Setting::WELCOME_PROMO_CODE);
        $promoCode = null;
        if ($welcomeCodeSetting == Setting::TRUE) {
            $promoCode = $this->promoCodeService->createPromoCodeForWelcomeUser($user);
        }
        $this->sendWelcomeMessage($user, $promoCode);
        return $user;
    }

    public function sendWelcomeMessage(User $user, ?PromoCode $promoCode): void
    {
        $welcomeMessage = new WelcomeMessage(
            $user->getEmail(),
            $user->getFullName(),
            $promoCode?->getCode(),
            $promoCode?->getDiscount()
        );
        $this->messageBusInterface->dispatch($welcomeMessage);
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

    public function updateUserPassword(User $user, string $newPassword): void
    {
        $hashedPassword = $this->passwordHasher->hashPassword($user, $newPassword);
        $user->setPassword($hashedPassword);
        $this->entityManager->persist($user);
        $this->entityManager->flush();
    }
}