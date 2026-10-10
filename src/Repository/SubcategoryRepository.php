<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Subcategory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Subcategory>
 */
class SubcategoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Subcategory::class);
    }

    public function getAverageMarkup(): ?float
    {
        $qb = $this->createQueryBuilder('s')
            ->select('AVG(s.markup) as avg_markup')
            ->getQuery()
            ->getSingleScalarResult();

        return $qb !== null ? (float) $qb : null; // avg_markup
    }

    public function getLowestMarkup(): ?float
    {
        $qb = $this->createQueryBuilder('s')
            ->select('MIN(s.markup) as lowest_markup')
            ->getQuery()
            ->getSingleScalarResult();

        return $qb !== null ? (float) $qb : null; // lowest_markup
    }

    public function getHighestMarkup(): ?float
    {
        $qb = $this->createQueryBuilder('s')
            ->select('MAX(s.markup) as highest_markup')
            ->getQuery()
            ->getSingleScalarResult();

        return $qb !== null ? (float) $qb : null; // highest_markup
    }

    //    /**
    //     * @return Subcategory[] Returns an array of Subcategory objects
    //     */
    //    public function findByExampleField($value): array
    //    {
    //        return $this->createQueryBuilder('s')
    //            ->andWhere('s.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->orderBy('s.id', 'ASC')
    //            ->setMaxResults(10)
    //            ->getQuery()
    //            ->getResult()
    //        ;
    //    }

    //    public function findOneBySomeField($value): ?Subcategory
    //    {
    //        return $this->createQueryBuilder('s')
    //            ->andWhere('s.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }
}