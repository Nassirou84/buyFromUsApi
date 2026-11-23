<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Order;
use App\Entity\User;
use App\Enumeration\OrderStatusEnum;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Order>
 */
class OrderRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Order::class);
    }

    public function countActiveOrders(User $user)
    {
        // Get orders total where status is not "delivered" nor "cancelled"
        return $this->createQueryBuilder('o')
            ->select('COUNT(o.id)')
            ->andWhere('o.customer = :user')
            ->andWhere('o.status != :status1')
            ->andWhere('o.status != :status2')
            ->setParameter('user', $user)
            ->setParameter('status1', OrderStatusEnum::DELIVERED->value)
            ->setParameter('status2', OrderStatusEnum::CANCELLED->value)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function getTotalSpents(User $user)
    {
        return $this->createQueryBuilder('o')
            ->select('SUM(o.orderPrice)')
            ->andWhere('o.customer = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Orders that are out for delivery but not yet assigned to a driver.
     *
     * @return Order[]
     */
    public function findAwaitingDelivery(int $limit, int $offset): array
    {
        return $this->awaitingDeliveryQueryBuilder()
            ->andWhere('o.status = :status')
            ->setParameter('status', OrderStatusEnum::OUT_FOR_DELIVERY->value)
            ->orderBy('o.createdAt', 'ASC')
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->getQuery()
            ->getResult();
    }

    public function countAwaitingDelivery(): int
    {
        return (int) $this->awaitingDeliveryQueryBuilder()
            ->andWhere('o.status = :status')
            ->setParameter('status', OrderStatusEnum::OUT_FOR_DELIVERY->value)
            ->select('COUNT(o.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function awaitingDeliveryQueryBuilder(): \Doctrine\ORM\QueryBuilder
    {
        return $this->createQueryBuilder('o')
            ->leftJoin('o.assignDelivery', 'ad')
            ->andWhere('o.status = :status')
            ->andWhere('ad.id IS NULL')
            ->setParameter('status', OrderStatusEnum::OUT_FOR_DELIVERY->value);
    }

    //    /**
    //     * @return Order[] Returns an array of Order objects
    //     */
    //    public function findByExampleField($value): array
    //    {
    //        return $this->createQueryBuilder('o')
    //            ->andWhere('o.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->orderBy('o.id', 'ASC')
    //            ->setMaxResults(10)
    //            ->getQuery()
    //            ->getResult()
    //        ;
    //    }

    //    public function findOneBySomeField($value): ?Order
    //    {
    //        return $this->createQueryBuilder('o')
    //            ->andWhere('o.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }
}