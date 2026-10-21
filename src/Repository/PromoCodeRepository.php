<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PromoCode;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PromoCode>
 */
class PromoCodeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PromoCode::class);
    }

    public function findAllUsersDiscount(): ?PromoCode
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.terms = :val')
            ->andWhere('p.expiresAt IS NULL OR p.expiresAt > :now')
            ->andWhere('p.startsAt IS NULL OR p.startsAt <= :now')
            ->andWhere('p.isActive = true')
            ->andWhere('p.type = :type')
            ->setParameter('val', PromoCode::APPLY_ALL_USERS)
            ->setParameter('type', PromoCode::TYPE_PERCENTAGE)
            ->setParameter('now', new \DateTime())
            ->orderBy('p.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult()
        ;
    }

    //    /**
    //     * @return PromoCode[] Returns an array of PromoCode objects
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

    //    public function findOneBySomeField($value): ?PromoCode
    //    {
    //        return $this->createQueryBuilder('p')
    //            ->andWhere('p.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }
}