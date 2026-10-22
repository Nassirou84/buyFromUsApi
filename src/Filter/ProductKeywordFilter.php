<?php

declare(strict_types=1);

namespace App\Filter;

use ApiPlatform\Doctrine\Orm\Filter\FilterInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use Doctrine\ORM\QueryBuilder;

use function is_string;
use function sprintf;

final class ProductKeywordFilter implements FilterInterface
{
    private const PARAMETER_NAME = 'keyword';

    private const SEARCHED_PROPERTIES = ['uid', 'brand', 'title', 'seller'];

    public function apply(QueryBuilder $queryBuilder, QueryNameGeneratorInterface $queryNameGenerator, string $resourceClass, ?Operation $operation = null, array $context = []): void
    {
        $keyword = $context['filters'][self::PARAMETER_NAME] ?? null;

        if (!is_string($keyword) || '' === ($keyword = trim($keyword))) {
            return;
        }

        $alias = $queryBuilder->getRootAliases()[0];
        $parameterName = $queryNameGenerator->generateParameterName(self::PARAMETER_NAME);

        $conditions = $queryBuilder->expr()->orX();
        foreach (self::SEARCHED_PROPERTIES as $property) {
            $conditions->add($queryBuilder->expr()->like(sprintf('LOWER(%s.%s)', $alias, $property), ':' . $parameterName));
        }

        $queryBuilder
            ->andWhere($conditions)
            ->setParameter($parameterName, '%' . addcslashes(mb_strtolower($keyword), '%_\\') . '%');
    }

    public function getDescription(string $resourceClass): array
    {
        return [
            self::PARAMETER_NAME => [
                'property' => implode(', ', self::SEARCHED_PROPERTIES),
                'type' => 'string',
                'required' => false,
                'description' => sprintf('Partial, case-insensitive search on: %s.', implode(', ', self::SEARCHED_PROPERTIES)),
            ],
        ];
    }
}
