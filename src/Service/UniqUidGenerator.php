<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;

class UniqUidGenerator
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private CacheItemPoolInterface $cacheInterface
    ) {
    }

    public function generateUniqueUid(string $classEntity): string
    {
        $repository = $this->entityManager->getRepository($classEntity);
        $uid = '';
        $prefix = '';
        if ('App\Entity\Basket' === $classEntity) {
            $prefix = 'B-';
        } elseif ('App\Entity\ShoppingRequest' === $classEntity) {
            $prefix = 'R-';
        } elseif ('App\Entity\Order' === $classEntity) {
            $prefix = 'O-';
        } elseif ('App\Entity\User' === $classEntity) {
            $prefix = 'U-';
        }

        do {
            $uid = $prefix . substr(bin2hex(random_bytes(3)), 0, 6);
        } while ($repository->findOneBy(['uid' => $uid]));

        return (string) $uid;
    }

    public function generateUniqueTokenForUser(): string
    {
        $token = '';
        do {
            $token = bin2hex(random_bytes(32));
            $exists = $this->cacheInterface->hasItem(
                hash('sha256', $token)
            );
        } while ($exists);

        return $token;
    }

    public function generateUniqueUidForBasket(): string
    {
        $token = '';
        do {
            $token = 'guest_basket_' . substr(uniqid(), -10);
            $exists = $this->cacheInterface->hasItem(
                $token
            );
        } while ($exists);
        return $token;
    }
}