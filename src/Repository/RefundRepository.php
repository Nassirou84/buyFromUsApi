<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Refund;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Refund>
 */
class RefundRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Refund::class);
    }

    /**
     * Refunds whose gateway outcome isn't final yet (pending, processing, needs_attention),
     * oldest first. Rows created after $createdBefore are skipped, so a refund still being
     * submitted by a request isn't picked up mid-flight.
     *
     * @return list<Refund>
     */
    public function findUnresolved(string $provider, DateTimeImmutable $createdBefore, ?int $limit = null): array
    {
        $qb = $this->createQueryBuilder('r')
            ->andWhere('r.status IN (:statuses)')
            ->andWhere('r.provider = :provider')
            ->andWhere('r.createdAt <= :createdBefore')
            ->setParameter('statuses', [Refund::STATUS_PENDING, Refund::STATUS_PROCESSING, Refund::STATUS_NEEDS_ATTENTION])
            ->setParameter('provider', $provider)
            ->setParameter('createdBefore', $createdBefore)
            ->orderBy('r.createdAt', 'ASC');

        if (null !== $limit) {
            $qb->setMaxResults($limit);
        }

        /** @var list<Refund> $result */
        $result = $qb->getQuery()->getResult();

        return $result;
    }

    //    /**
    //     * @return Refund[] Returns an array of Refund objects
    //     */
    //    public function findByExampleField($value): array
    //    {
    //        return $this->createQueryBuilder('r')
    //            ->andWhere('r.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->orderBy('r.id', 'ASC')
    //            ->setMaxResults(10)
    //            ->getQuery()
    //            ->getResult()
    //        ;
    //    }

    //    public function findOneBySomeField($value): ?Refund
    //    {
    //        return $this->createQueryBuilder('r')
    //            ->andWhere('r.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }
}
