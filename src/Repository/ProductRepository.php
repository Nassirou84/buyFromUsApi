<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Photo;
use App\Entity\Product;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Product>
 */
class ProductRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Product::class);
    }


    /**
     * Distinct brands starting with the (already lower-cased) text.
     *
     * @return list<string>
     */
    public function findBrandsStartingWith(string $text, int $limit): array
    {
        return $this->createQueryBuilder('p')
            ->select('DISTINCT p.brand')
            ->andWhere('LOWER(p.brand) LIKE :prefix')
            ->setParameter('prefix', self::escapeLike($text) . '%')
            ->orderBy('p.brand', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getSingleColumnResult();
    }

    /**
     * Products whose title contains the (already lower-cased) text, the ones that start with it first, then the newest.
     * May contain several rows with the same title (the same product from several sellers): callers de-duplicate.
     *
     * @return list<array{id: int, title: string, slug: ?string, productCondition: ?string}>
     */
    public function findTitlesMatching(string $text, int $limit): array
    {
        return $this->createQueryBuilder('p')
            ->select('p.id', 'p.title', 'p.slug', 'p.productCondition')
            ->addSelect('CASE WHEN LOWER(p.title) LIKE :prefix THEN 0 ELSE 1 END AS HIDDEN startsWith')
            ->andWhere('LOWER(p.title) LIKE :contains')
            ->setParameter('prefix', self::escapeLike($text) . '%')
            ->setParameter('contains', '%' . self::escapeLike($text) . '%')
            ->orderBy('startsWith', 'ASC')
            ->addOrderBy('p.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getArrayResult();
    }

    /**
     * Cover image URL per product id: the photo flagged as cover, else the oldest photo. Products without photos are absent.
     *
     * @param list<int> $productIds
     *
     * @return array<int, string>
     */
    public function findCoverImageUrls(array $productIds): array
    {
        if ([] === $productIds) {
            return [];
        }

        $rows = $this->getEntityManager()->createQueryBuilder()
            ->select('IDENTITY(ph.product) AS productId', 'ph.url')
            ->from(Photo::class, 'ph')
            ->andWhere('ph.product IN (:ids)')
            ->setParameter('ids', $productIds)
            ->orderBy('ph.isCover', 'DESC')
            ->addOrderBy('ph.id', 'ASC')
            ->getQuery()
            ->getArrayResult();

        $urls = [];
        foreach ($rows as $row) {
            $urls[(int) $row['productId']] ??= $row['url'];
        }

        return $urls;
    }

    private static function escapeLike(string $text): string
    {
        return addcslashes($text, '%_\\');
    }

    public function countRecentlyUpdatedInDays(int $days): int
    {
        return $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->andWhere('p.updatedAt IS NOT NULL')
            ->andWhere('p.updatedAt >= :date')
            ->setParameter('date', new \DateTime("-$days days"))
            ->getQuery()
            ->getSingleScalarResult();
    }
    //    /**
    //     * @return Product[] Returns an array of Product objects
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

    //    public function findOneBySomeField($value): ?Product
    //    {
    //        return $this->createQueryBuilder('p')
    //            ->andWhere('p.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }
}