<?php

declare(strict_types=1);

namespace App\Service;

class PriceCalculator
{
    public const TAX_RATE = 0.18; // 18% tax rate

    /**
     * Markup tiers as [exclusive upper bound in USD, margin]; prices above the last bound get TOP_TIER_MARGIN.
     * Shared with the price range filter so listing filters match the displayed price.
     */
    public const MARGIN_TIERS = [
        [50.0, 0.17], // 17% markup for products under $50
        [250.0, 0.15], // 15% markup for products between $50 and $250
        [750.0, 0.13], // 13% markup for products between $250 and $750
    ];
    public const TOP_TIER_MARGIN = 0.10; // 10% markup for products over $750

    private static ?CurrencyProvider $currencyProvider = null;

    public static function useCurrencyProvider(CurrencyProvider $currencyProvider): void
    {
        self::$currencyProvider = $currencyProvider;
    }

    public static function marginFor(float $basePrice): float
    {
        foreach (self::MARGIN_TIERS as [$upperBound, $margin]) {
            if ($basePrice < $upperBound) {
                return $margin;
            }
        }

        return self::TOP_TIER_MARGIN;
    }

    public static function calculate(float $basePrice): float
    {
        $taxRate = self::$currencyProvider?->getTaxRate() ?? self::TAX_RATE;
        $rate = self::$currencyProvider?->getRate() ?? 600;

        $benefitMargin = self::marginFor($basePrice);

        $margin = $basePrice * $benefitMargin;
        $taxes = ($basePrice + $margin) * $taxRate;

        return round(($basePrice + $margin + $taxes) * $rate);
    }
}
