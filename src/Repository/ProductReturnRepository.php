<?php

namespace App\Repository;

use App\Entity\AssignDelivery;
use App\Entity\ProductReturn;
use App\Enumeration\ProductReturnStatusEnum;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ProductReturn>
 */
class ProductReturnRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProductReturn::class);
    }

    /**
     * Initiated returns that no driver has been assigned to collect yet.
     *
     * @return ProductReturn[]
     */
    public function findAwaitingCollection(int $limit, int $offset): array
    {
        return $this->awaitingCollectionQueryBuilder()
            ->orderBy('pr.createdAt', 'ASC')
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->getQuery()
            ->getResult();
    }

    public function countAwaitingCollection(): int
    {
        return (int) $this->awaitingCollectionQueryBuilder()
            ->select('COUNT(pr.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function awaitingCollectionQueryBuilder(): \Doctrine\ORM\QueryBuilder
    {
        return $this->createQueryBuilder('pr')
            ->leftJoin('pr.assignDeliveries', 'ad', 'WITH', 'ad.type = :collectionType')
            ->andWhere('pr.status = :status')
            ->andWhere('ad.id IS NULL')
            ->setParameter('status', ProductReturnStatusEnum::INITIATED->value)
            ->setParameter('collectionType', AssignDelivery::TYPE_RETURN_COLLECTION);
    }

//    /**
//     * @return ProductReturn[] Returns an array of ProductReturn objects
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

//    public function findOneBySomeField($value): ?ProductReturn
//    {
//        return $this->createQueryBuilder('p')
//            ->andWhere('p.exampleField = :val')
//            ->setParameter('val', $value)
//            ->getQuery()
//            ->getOneOrNullResult()
//        ;
//    }
}
