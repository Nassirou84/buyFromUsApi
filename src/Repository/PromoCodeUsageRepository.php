<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PromoCodeUsage;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PromoCodeUsage>
 */
class PromoCodeUsageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PromoCodeUsage::class);
    }

    public function hasUserUsedPromoCode($user, $promoCode): bool
    {
        return (bool) $this->createQueryBuilder('p')
            ->andWhere('p.user = :user')
            ->andWhere('p.promoCode = :promoCode')
            ->setParameter('user', $user)
            ->setParameter('promoCode', $promoCode)
            ->getQuery()
            ->getOneOrNullResult();
    }

    //    /**
    //     * @return PromoCodeUsage[] Returns an array of PromoCodeUsage objects
    //     */
    //    public function findByExampleField($value): array
    //    {
    //        return $this->createQueryBuilder('p')
    //            ->andWhere('p.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->orderBy('p.id', 'ASC')
    //            ->setMaxResults(10)
    //            ->getQuery()
    //            ->getResult()
    //        ;
    //    }

    //    public function findOneBySomeField($value): ?PromoCodeUsage
    //    {
    //        return $this->createQueryBuilder('p')
    //            ->andWhere('p.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }
}