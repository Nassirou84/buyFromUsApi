<?php

namespace App\Service;

class PriceCalculator
{
  const CURRENCY_RATE_FILE = __DIR__ . '/../../public/data/currency_rate.json';
  const TAX_RATE = 0.18; // 18% tax rate


  public static function calculate(float $basePrice): float
  {
    $currency = getenv('currency') ?: 'xof';
    $taxRate = getenv('taxe_rate') !== false ? (float) getenv('taxe_rate') : self::TAX_RATE;
    $rate = file_get_contents(self::CURRENCY_RATE_FILE);
    $rate = $rate ? json_decode($rate, true)[$currency] : null;


    if ($rate === null) {
      switch (strtolower($currency)) {
        case 'xof':
          $rate = 600;
          break;
        case 'gnf':
          $rate = 9500;
          break;
        default:
          $rate = 1;
      }
    }

    $benefitMargin = 0;

    switch ($basePrice) {
      case $basePrice < 50:
        $benefitMargin = 0.17; // 17% markup for products under $50
        break;
      case $basePrice >= 50 && $basePrice < 250:
        $benefitMargin = 0.15; // 15% markup for products between $50 and $250
        break;
      case $basePrice >= 250 && $basePrice < 750:
        $benefitMargin = 0.13; // 13% markup for products over $250
        break;
      case $basePrice >= 750:
        $benefitMargin = 0.10; // 10% markup for products over $750
        break;
      default:
        $benefitMargin = 0.2; // Default markup if none of the above conditions are met
        break;
    }

    $margin = $basePrice * $benefitMargin;
    $taxes = ($basePrice + $margin) * $taxRate;

    return round(($basePrice + $margin + $taxes) * $rate);
  }
}