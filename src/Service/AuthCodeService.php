<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Cache\CacheItemPoolInterface;

class AuthCodeService
{
    public function __construct(
        private CacheItemPoolInterface $cacheInterface,
    ) {
    }

    public function generateAuthCode(): string
    {
        return (string) random_int(100000, 999999);
    }

    public function validateAuthCode(string $inputCode, string $storedCode): bool
    {
        $inputCode = hash('sha256', $inputCode);
        return hash_equals($storedCode, $inputCode);
    }

    public function generateAndStoreAuthCode(int $userId): string
    {
        $authCode = $this->generateAuthCode();
        $hashedAuthCode = hash('sha256', $authCode);
        $cacheKey = 'auth_code_' . $userId;
        $item = $this->cacheInterface->getItem($cacheKey);
        $item->set($hashedAuthCode);
        $item->expiresAfter(900);
        $this->cacheInterface->save($item);

        return $authCode;
    }

    public function getStoredAuthCode(int $userId): ?string
    {
        $item = $this->cacheInterface->getItem('auth_code_' . $userId);

        return $item->isHit() ? $item->get() : null;
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
        $this->cacheInterface->deleteItem('auth_code_' . $userId);
    }
}