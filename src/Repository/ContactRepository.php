<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Contact;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Contact>
 */
class ContactRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Contact::class);
    }

    /**
     * Returns true when the same email has already submitted an identical
     * message, so we can reject duplicate submissions.
     */
    public function existsByEmailAndMessage(string $email, string $message): bool
    {
        $count = (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->andWhere('c.email = :email')
            ->andWhere('c.message = :message')
            ->setParameter('email', $email)
            ->setParameter('message', $message)
            ->getQuery()
            ->getSingleScalarResult();

        return $count > 0;
    }
}
