<?php

declare(strict_types=1);

namespace App\Filter;

use ApiPlatform\Doctrine\Orm\Filter\FilterInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Service\CurrencyProvider;
use App\Service\PriceCalculator;
use Doctrine\ORM\QueryBuilder;

use function is_float;
use function is_int;
use function is_string;
use function sprintf;

/**
 * Filters on the price shown to customers (Product::getActualPrice(), in the storefront currency),
 * not on the USD source price: minPrice / maxPrice are inclusive and expressed in that currency.
 */
final class ProductPriceRangeFilter implements FilterInterface
{
    private const MIN_PARAMETER = 'minPrice';
    private const MAX_PARAMETER = 'maxPrice';

    public function __construct(private readonly CurrencyProvider $currencyProvider)
    {
    }

    public function apply(QueryBuilder $queryBuilder, QueryNameGeneratorInterface $queryNameGenerator, string $resourceClass, ?Operation $operation = null, array $context = []): void
    {
        $min = $this->readPrice($context['filters'][self::MIN_PARAMETER] ?? null);
        $max = $this->readPrice($context['filters'][self::MAX_PARAMETER] ?? null);

        if (null === $min && null === $max) {
            return;
        }

        $alias = $queryBuilder->getRootAliases()[0];
        $factor = (1 + $this->currencyProvider->getTaxRate()) * $this->currencyProvider->getRate();
        $price = sprintf('(%s.usdPrice * %s * %s)', $alias, $this->marginMultiplierDql($alias), $this->literal($factor));

        // Bounds are inlined as numeric literals: DBAL binds floats as strings, which SQLite compares as text.
        // The displayed price is rounded to a whole unit, so a price of 999.6 is shown (and filtered) as 1000.
        if (null !== $min) {
            $queryBuilder->andWhere(sprintf('%s >= %s', $price, $this->literal($min - 0.5)));
        }

        if (null !== $max) {
            $queryBuilder->andWhere(sprintf('%s < %s', $price, $this->literal($max + 0.5)));
        }
    }

    public function getDescription(string $resourceClass): array
    {
        return [
            self::MIN_PARAMETER => [
                'property' => 'actualPrice',
                'type' => 'float',
                'required' => false,
                'description' => 'Minimum displayed price (inclusive), in the storefront currency.',
            ],
            self::MAX_PARAMETER => [
                'property' => 'actualPrice',
                'type' => 'float',
                'required' => false,
                'description' => 'Maximum displayed price (inclusive), in the storefront currency.',
            ],
        ];
    }

    private function readPrice(mixed $value): ?float
    {
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            return null;
        }

        if (!is_numeric($value) || !is_finite((float) $value) || (float) $value < 0) {
            return null;
        }

        return (float) $value;
    }

    /**
     * DQL mirror of PriceCalculator::marginFor() + 1: the markup tier depends on the USD price.
     */
    private function marginMultiplierDql(string $alias): string
    {
        $cases = '';
        foreach (PriceCalculator::MARGIN_TIERS as [$upperBound, $margin]) {
            $cases .= sprintf(' WHEN %s.usdPrice < %s THEN %s', $alias, $this->literal($upperBound), $this->literal(1 + $margin));
        }

        return sprintf('(CASE%s ELSE %s END)', $cases, $this->literal(1 + PriceCalculator::TOP_TIER_MARGIN));
    }

    private function literal(float $number): string
    {
        return rtrim(rtrim(number_format($number, 6, '.', ''), '0'), '.');
    }
}
