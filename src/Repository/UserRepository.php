<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;

use function sprintf;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository implements PasswordUpgraderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    /**
     * Used to upgrade (rehash) the user's password automatically over time.
     */
    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(sprintf('Instances of "%s" are not supported.', $user::class));
        }

        $user->setPassword($newHashedPassword);
        $this->getEntityManager()->persist($user);
        $this->getEntityManager()->flush();
    }

    /**
     * Roles that mark a user as staff. ROLE_USER is left out: it is the implicit baseline.
     */
    private const STAFF_ROLES = [
        User::ROLE_SUPER_ADMIN,
        User::ROLE_ADMIN,
        User::ROLE_MARKETING,
        User::ROLE_COURRIER,
        User::ROLE_ACCOUNTING,
    ];

    /**
     * Users that hold none of the staff roles.
     *
     * `roles` is stored as JSON, so each role name is matched as a quoted string.
     */
    public function createNonAdminQueryBuilder(string $alias = 'u'): QueryBuilder
    {
        $queryBuilder = $this->createQueryBuilder($alias)
            ->orderBy(sprintf('%s.id', $alias), 'DESC');

        foreach (self::STAFF_ROLES as $index => $role) {
            $queryBuilder
                ->andWhere(sprintf('%s.roles NOT LIKE :role%d', $alias, $index))
                ->setParameter('role' . $index, '%"' . $role . '"%');
        }

        return $queryBuilder;
    }

    public function hasSuperAdmin(): bool
    {
        return (int) $this->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->andWhere('u.roles LIKE :role')
            ->setParameter('role', '%"' . User::ROLE_SUPER_ADMIN . '"%')
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }

    //    /**
    //     * @return User[] Returns an array of User objects
    //     */
    //    public function findByExampleField($value): array
    //    {
    //        return $this->createQueryBuilder('u')
    //            ->andWhere('u.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->orderBy('u.id', 'ASC')
    //            ->setMaxResults(10)
    //            ->getQuery()
    //            ->getResult()
    //        ;
    //    }

    //    public function findOneBySomeField($value): ?User
    //    {
    //        return $this->createQueryBuilder('u')
    //            ->andWhere('u.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }
}