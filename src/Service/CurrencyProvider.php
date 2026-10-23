<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Setting;
use Symfony\Contracts\Service\ResetInterface;

use function is_array;

/**
 * Resolves the storefront currency (Setting "base_currency", falling back to TARGET_CURRENCY),
 * its USD rate (public/data/currency_rate.json) and the tax rate (Setting "tax_rate", falling back
 * to TAXE_RATE). Values are memoized per request / worker message and reset by the kernel, so a
 * changed setting is picked up on the next request.
 */
class CurrencyProvider implements ResetInterface
{
    public const RATE_FILE = __DIR__ . '/../../public/data/currency_rate.json';
    public const USD = 'usd';

    private const DEFAULT_CURRENCY = 'fcfa';
    private const DEFAULT_RATES = [
        'fcfa' => 600.0,
        'gnf' => 9500.0,
    ];

    public const DEFAULT_TAX_RATE = 0.18;

    private ?string $currency = null;

    private ?float $taxRate = null;

    /** @var array<string, float>|null */
    private ?array $rates = null;

    public function __construct(
        private readonly SettingService $settingService,
        private readonly string $defaultCurrency = '',
        private readonly string $defaultTaxRate = '',
    ) {
    }

    public function getCurrency(): string
    {
        if (null !== $this->currency) {
            return $this->currency;
        }

        $configured = $this->settingService->getSetting(Setting::BASE_CURRENCY);
        $candidates = [$configured, $this->defaultCurrency, self::DEFAULT_CURRENCY];

        foreach ($candidates as $candidate) {
            $candidate = strtolower(trim((string) $candidate));
            if ('' !== $candidate && $this->isSupported($candidate)) {
                return $this->currency = $candidate;
            }
        }

        return $this->currency = self::DEFAULT_CURRENCY;
    }

    /**
     * Units of the current currency for 1 USD.
     */
    public function getRate(): float
    {
        $currency = $this->getCurrency();

        if (self::USD === $currency) {
            return 1.0;
        }

        return $this->loadRates()[$currency] ?? self::DEFAULT_RATES[$currency] ?? 1.0;
    }

    /**
     * Tax rate as a fraction (0.18 = 18%). A configured value above 1 is read as a percentage.
     */
    public function getTaxRate(): float
    {
        if (null !== $this->taxRate) {
            return $this->taxRate;
        }

        $configured = $this->settingService->getSetting(Setting::TAX_RATE);

        foreach ([$configured, $this->defaultTaxRate] as $candidate) {
            $candidate = str_replace(['%', ','], ['', '.'], trim((string) $candidate));
            if (!is_numeric($candidate) || (float) $candidate < 0) {
                continue;
            }

            $rate = (float) $candidate;

            return $this->taxRate = $rate > 1 ? $rate / 100 : $rate;
        }

        return $this->taxRate = self::DEFAULT_TAX_RATE;
    }

    public function reset(): void
    {
        $this->currency = null;
        $this->taxRate = null;
        $this->rates = null;
    }

    private function isSupported(string $currency): bool
    {
        return self::USD === $currency
            || isset($this->loadRates()[$currency])
            || isset(self::DEFAULT_RATES[$currency]);
    }

    /**
     * @return array<string, float>
     */
    private function loadRates(): array
    {
        if (null !== $this->rates) {
            return $this->rates;
        }

        $this->rates = [];
        $raw = is_file(self::RATE_FILE) ? file_get_contents(self::RATE_FILE) : false;
        $decoded = false !== $raw ? json_decode($raw, true) : null;

        if (is_array($decoded)) {
            foreach ($decoded as $code => $rate) {
                if (is_numeric($rate) && (float) $rate > 0) {
                    $this->rates[strtolower((string) $code)] = (float) $rate;
                }
            }
        }

        return $this->rates;
    }
}
