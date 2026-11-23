<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ProductReturnItem;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ProductReturnItem>
 */
class ProductReturnItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProductReturnItem::class);
    }
}
