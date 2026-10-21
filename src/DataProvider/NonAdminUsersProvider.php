<?php

declare(strict_types=1);

namespace App\DataProvider;

use ApiPlatform\Doctrine\Orm\Paginator;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGenerator;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\Pagination\Pagination;
use ApiPlatform\State\ProviderInterface;
use App\Entity\User;
use App\Filter\UserKeywordFilter;
use App\Repository\UserRepository;
use Doctrine\ORM\Tools\Pagination\Paginator as DoctrinePaginator;

/**
 * Collection provider for GET /api/users: lists only users without a staff role
 * (super admin, admin, marketing, courier, accounting).
 * Access is restricted to ROLE_ADMIN by the operation's `security` expression.
 */
class NonAdminUsersProvider implements ProviderInterface
{
    public function __construct(
        private UserRepository $userRepository,
        private Pagination $pagination,
        private UserKeywordFilter $keywordFilter,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        $queryBuilder = $this->userRepository->createNonAdminQueryBuilder();

        // A custom provider bypasses API Platform's automatic filter handling, so apply it here.
        $this->keywordFilter->apply($queryBuilder, new QueryNameGenerator(), User::class, $operation, $context);

        if (!$this->pagination->isEnabled($operation, $context)) {
            return $queryBuilder->getQuery()->getResult();
        }

        [, $offset, $limit] = $this->pagination->getPagination($operation, $context);

        $query = $queryBuilder
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery();

        return new Paginator(new DoctrinePaginator($query, false));
    }
}
