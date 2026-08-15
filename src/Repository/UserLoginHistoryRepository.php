<?php

namespace App\Repository;

use App\Entity\User;
use App\Entity\UserLoginHistory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UserLoginHistory>
 */
class UserLoginHistoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserLoginHistory::class);
    }

    public function isKnownDevice(User $user, string $userAgent): bool
    {
        $userAgentHash = hash('sha256', $userAgent);

        $result = $this->createQueryBuilder('h')
            ->select('COUNT(h.id)')
            ->where('h.user = :user')
            ->andWhere('h.userAgentHash = :hash')
            ->andWhere('h.isTrusted = true')
            ->setParameter('user', $user)
            ->setParameter('hash', $userAgentHash)
            ->getQuery()
            ->getSingleScalarResult();

        return $result > 0;
    }

    public function findDeviceByUserAgent(User $user, string $userAgent): ?UserLoginHistory
    {
        $userAgentHash = hash('sha256', $userAgent);

        return $this->createQueryBuilder('h')
            ->where('h.user = :user')
            ->andWhere('h.userAgentHash = :hash')
            ->setParameter('user', $user)
            ->setParameter('hash', $userAgentHash)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function updateLastUsed(User $user, string $userAgent): void
    {
        $device = $this->findDeviceByUserAgent($user, $userAgent);

        if ($device) {
            $device->setLastUsedAt(new \DateTime());
            $this->getEntityManager()->flush();
        }
    }

    public function createDeviceEntry(User $user, string $userAgent, ?string $ipAddress): UserLoginHistory
    {
        $history = new UserLoginHistory();
        $history->setUser($user);
        $history->setUserAgent($userAgent);
        $history->setIpAddress($ipAddress);
        $history->setFirstSeenAt(new \DateTime());
        $history->setLastUsedAt(new \DateTime());
        $history->setIsTrusted(false); // New devices are untrusted by default

        $this->getEntityManager()->persist($history);
        $this->getEntityManager()->flush();

        return $history;
    }

    public function trustDevice(User $user, string $userAgent): void
    {
        $device = $this->findDeviceByUserAgent($user, $userAgent);

        if ($device) {
            $device->setIsTrusted(true);
            $device->setLastUsedAt(new \DateTime());
            $this->getEntityManager()->flush();
        }
    }

    //    /**
    //     * @return UserLoginHistory[] Returns an array of UserLoginHistory objects
    //     */
    //    public function findByExampleField($value): array
    //    {
    //        return $this->createQueryBuilder('u')
    //            ->andWhere('u.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->orderBy('u.id', 'ASC')
    //            ->setMaxResults(10)
    //            ->getQuery()
    //            ->getResult()
    //        ;
    //    }

    //    public function findOneBySomeField($value): ?UserLoginHistory
    //    {
    //        return $this->createQueryBuilder('u')
    //            ->andWhere('u.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }
}