<?php

namespace App\Repository;

use App\Entity\EmailQueue;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EmailQueue>
 */
class EmailQueueRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EmailQueue::class);
    }

    /**
     * @return EmailQueue[] Returns an array of EmailQueue objects
     */
    public function getEmailBatch(int $batchSize, int $recoveryThresholdMinutes = 5): array
    {
        $now = new \DateTimeImmutable();
        $staleProcessingThreshold = $now->modify(sprintf('-%d minutes', $recoveryThresholdMinutes));

        return $this->createQueryBuilder('eq')
            ->where('eq.status IN (:eligibleStatuses)')
            ->orWhere('eq.status = :processingStatus AND (eq.updatedAt IS NULL OR eq.updatedAt <= :staleProcessingThreshold)')
            ->andWhere('eq.sendAfter <= :now')
            ->andWhere('eq.attemps < eq.maxAttemps')
            ->setParameter('eligibleStatuses', [EmailQueue::STATUS_PENDING, EmailQueue::STATUS_RETRYING])
            ->setParameter('processingStatus', EmailQueue::STATUS_PROCESSING)
            ->setParameter('staleProcessingThreshold', $staleProcessingThreshold)
            ->setParameter('now', $now)
            ->orderBy('eq.priority', 'ASC')
            ->addOrderBy('eq.createdAt', 'ASC')
            ->setMaxResults($batchSize)
            ->getQuery()
            ->getResult();
    }

    //    public function findOneBySomeField($value): ?EmailQueue
    //    {
    //        return $this->createQueryBuilder('e')
    //            ->andWhere('e.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }
}