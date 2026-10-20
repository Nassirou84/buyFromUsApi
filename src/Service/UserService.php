<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Setting;
use App\Entity\User;
use App\Message\AdminResetLinkMessage;
use App\Message\NewAdminCreatedMessage;
use App\Message\WelcomeMessage;
use App\Repository\UserRepository;
use App\Service\PromoCodeService;
use App\Entity\PromoCode;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

class UserService
{
    private const RESET_TOKEN_TTL = 900; // 15 minutes
    private const NEW_ADMIN_TOKEN_TTL = 86400; // 24 hours
    private const ADMIN_LIST_TTL = 300; // 5 minutes
    private const ADMIN_LIST_VERSION_KEY = 'admin_users_version';

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
        private OrderService $orderService
    ) {
    }

    public function createUser(User $user): User
    {
        // Never trust client-supplied roles on public registration.
        $user->setRoles(['ROLE_USER']);
        $hashedPassword = $this->passwordHasher->hashPassword($user, $user->getPassword());
        $user->setPassword($hashedPassword);
        $token = $this->tokenService->generateToken();
        $user->setRegistrationToken($token);
        $user->setRegistrationTokenCreatedAt(new DateTime());
        $this->entityManager->persist($user);
        $this->entityManager->flush();
        $this->basketService->createBasketForUser($user);
        $this->orderService->assignPreviousOrderToUser($user);
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
            $promoCode?->getExpiresAt()?->format('Y-m-d'),
            $promoCode?->getDiscount()
        );
        $this->messageBusInterface->dispatch($welcomeMessage);
    }

    public function generateChangePasswordToken(User $user, int $ttlSeconds = 900): string
    {
        $token = $this->uniqUidGenerator->generateUniqueTokenForUser();
        $hashedToken = hash('sha256', $token);

        $userEmail = $user->getEmail();

        $this->cacheInterface->get($hashedToken, static function (ItemInterface $item) use ($userEmail, $ttlSeconds) {
            $item->expiresAfter($ttlSeconds);
            return $userEmail;
        });

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

    public function createAdminUser(array $data): User
    {
        $existingUser = $this->userRepository->findOneBy(['email' => $data['email']]);
        if ($existingUser) {
            throw new \InvalidArgumentException('User with this email already exists.');
        }

        $user = new User();
        $user->setEmail($data['email']);
        $user->setFirstName($data['firstName']);
        $user->setLastName($data['lastName']);
        $user->setRoles(array_unique([...$data['roles'], User::ROLE_ADMIN]));
        $user->setIsGrantedAccess(true);
        $this->entityManager->persist($user);
        $this->entityManager->flush();
        $this->invalidateAdminUsersCache();

        $this->sendNewAdminMessage($user);

        return $user;
    }

    private function sendNewAdminMessage(User $user): void
    {
        $token = $this->generateChangePasswordToken($user, self::NEW_ADMIN_TOKEN_TTL);

        $this->messageBusInterface->dispatch(
            new NewAdminCreatedMessage(
                $user->getEmail(),
                $user->getFullName(),
                $token,
                intdiv(self::NEW_ADMIN_TOKEN_TTL, 60),
            ),
        );
    }

    public function sendAdminResetLink(User $admin): void
    {
        $token = $this->generateChangePasswordToken($admin, self::RESET_TOKEN_TTL);

        $this->messageBusInterface->dispatch(
            new AdminResetLinkMessage(
                $admin->getEmail(),
                $admin->getFullName(),
                $token,
            ),
        );
    }

    public function toggleAdminAccess(User $admin): User
    {
        $admin->setIsGrantedAccess(!$admin->isGrantedAccess());
        $this->entityManager->flush();
        $this->invalidateAdminUsersCache();

        return $admin;
    }

    public function findUserById(int $id): ?User
    {
        return $this->userRepository->find($id);
    }

    /**
     * Cached per page. Stores plain arrays (not entities) so nothing managed by Doctrine gets serialized.
     * A non-empty $name filters on the concatenation of firstName and lastName and bypasses the cache.
     *
     * @return array{users: list<array<string, mixed>>, all: int}
     */
    public function getAdminUsers(int $page = 1, string $name = ''): array
    {
        $page = max(1, $page);
        $name = trim($name);
        if ('' !== $name) {
            return $this->queryAdminUsers($page, $name);
        }

        $version = $this->cacheInterface->get(self::ADMIN_LIST_VERSION_KEY, static fn(): string => bin2hex(random_bytes(4)));

        return $this->cacheInterface->get(
            sprintf('admin_users_%s_page_%d', $version, $page),
            function (ItemInterface $item) use ($page): array {
                $item->expiresAfter(self::ADMIN_LIST_TTL);

                return $this->queryAdminUsers($page);
            }
        );
    }

    /**
     * Drops every cached page at once: the next read gets a fresh version key,
     * and the old entries simply expire.
     */
    private function invalidateAdminUsersCache(): void
    {
        $this->cacheInterface->delete(self::ADMIN_LIST_VERSION_KEY);
    }

    private function queryAdminUsers(int $page, string $name = ''): array
    {
        $qb = $this->userRepository->createQueryBuilder('u')
            ->where('u.roles LIKE :role')
            ->setParameter('role', '%' . User::ROLE_ADMIN . '%')
            ->orderBy('u.createdAt', 'DESC');

        if ('' !== $name) {
            $qb
                ->andWhere("CONCAT(u.firstName, ' ', u.lastName) LIKE :name OR CONCAT(u.lastName, ' ', u.firstName) LIKE :name")
                ->setParameter('name', '%' . addcslashes(preg_replace('/\s+/', ' ', $name), '%_\\') . '%');
        }

        $totalUsers = (clone $qb)
            ->select('COUNT(u.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $users = $qb
            ->setFirstResult(($page - 1) * 10)
            ->setMaxResults(10)
            ->getQuery()
            ->getResult();

        return [
            'users' => array_map(static fn(User $user): array => [
                'id' => $user->getId(),
                'email' => $user->getEmail(),
                'firstName' => $user->getFirstName(),
                'lastName' => $user->getLastName(),
                'roles' => $user->getRoles(),
                'createdAt' => $user->getCreatedAt()?->format('Y-m-d H:i:s'),
                'isGrantedAccess' => $user->isGrantedAccess(),
            ], $users),
            'all' => (int) $totalUsers,
        ];
    }
}