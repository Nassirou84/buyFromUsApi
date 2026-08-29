<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\String\Slugger\SluggerInterface;
use Psr\Cache\CacheItemPoolInterface;

class UniqUidGenerator
{
    public function __construct(
        private SluggerInterface $slugger,
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
            $uid = $this->slugger->slug($prefix . substr(uniqid(), -5));
        } while ($repository->findOneBy(['uid' => $uid]));

        return (string) $uid;
    }

    public function generateUniqueTokenForUser(): string
    {
        $token = '';

        do {
            $token = substr(uniqid(), -10);
            $exists = $this->cacheInterface->hasItem(
                hash('sha256', $token)
            );
        } while ($exists);

        return $token;
    }
}