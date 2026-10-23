<?php

declare(strict_types=1);

namespace App\Filter;

use ApiPlatform\Doctrine\Orm\Filter\FilterInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use DateTime;
use Doctrine\ORM\QueryBuilder;

use function in_array;
use function sprintf;

/**
 * `newArrivals=true` keeps products added in the last NEW_ARRIVALS_DAYS days, newest first
 * (unless the request already asks for an explicit order).
 */
final class ProductNewArrivalsFilter implements FilterInterface
{
    public const NEW_ARRIVALS_DAYS = 30;

    private const PARAMETER_NAME = 'newArrivals';

    public function apply(QueryBuilder $queryBuilder, QueryNameGeneratorInterface $queryNameGenerator, string $resourceClass, ?Operation $operation = null, array $context = []): void
    {
        $value = $context['filters'][self::PARAMETER_NAME] ?? null;

        if (!in_array($value, [true, 1, 'true', '1'], true)) {
            return;
        }

        $alias = $queryBuilder->getRootAliases()[0];
        $parameterName = $queryNameGenerator->generateParameterName(self::PARAMETER_NAME);

        $queryBuilder
            ->andWhere(sprintf('%s.createdAt >= :%s', $alias, $parameterName))
            ->setParameter($parameterName, new DateTime(sprintf('-%d days', self::NEW_ARRIVALS_DAYS)));

        if ([] === $queryBuilder->getDQLPart('orderBy')) {
            $queryBuilder->addOrderBy(sprintf('%s.createdAt', $alias), 'DESC');
        }
    }

    public function getDescription(string $resourceClass): array
    {
        return [
            self::PARAMETER_NAME => [
                'property' => 'createdAt',
                'type' => 'bool',
                'required' => false,
                'description' => sprintf('When true, only products added in the last %d days, newest first.', self::NEW_ARRIVALS_DAYS),
            ],
        ];
    }
}
