<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

class AuthCodeService
{
    public function __construct(
        private CacheInterface $cacheInterface,
    ) {
    }

    public function generateAuthCode(): string
    {
        return (string) random_int(100000, 999999);
    }

    public function validateAuthCode(string $inputCode, string $storedCode): bool
    {
        $inputCode = hash('sha256', $inputCode);
        return $inputCode === $storedCode;
    }

    public function generateAndStoreAuthCode(int $userId): string
    {
        $authCode = $this->generateAuthCode();
        $hashedAuthCode = hash('sha256', $authCode);
        $cacheKey = 'auth_code_' . $userId;

        // The cache key may already hold a previous (still valid) code, so
        // it must be cleared before writing, otherwise get() would return
        // the stale value instead of storing the freshly generated one.
        $this->cacheInterface->delete($cacheKey);
        $this->cacheInterface->get($cacheKey, static function (ItemInterface $item) use ($hashedAuthCode) {
            $item->expiresAfter(900);
            return $hashedAuthCode;
        });

        return $authCode;
    }

    public function getStoredAuthCode(int $userId): ?string
    {
        $cacheKey = 'auth_code_' . $userId;

        return $this->cacheInterface->get($cacheKey, static function () {
            return null; // Return null if the auth code is not found
        });
    }

    public function isAuthCodeValid(int $userId, string $inputCode): bool
    {
        $storedCode = $this->getStoredAuthCode($userId);
        if (null === $storedCode) {
            return false; // No auth code found for this user
        }
        return $this->validateAuthCode($inputCode, $storedCode);
    }

    public function removeAuthCode(int $userId): void
    {
        $cacheKey = 'auth_code_' . $userId;
        $this->cacheInterface->delete($cacheKey);
    }
}