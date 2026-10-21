<?php

declare(strict_types=1);

namespace App\Service;

use Stichoza\GoogleTranslate\GoogleTranslate;

class TranslatorService
{
    private GoogleTranslate $googleTranslate;

    public function __construct(
    ) {
        $this->googleTranslate = new GoogleTranslate();
    }

    public function translate(string $text, string $targetLanguage): string
    {
        return $this->googleTranslate->setTarget($targetLanguage)->translate($text);
    }

    public function translateProductData(array $productData, string $targetLanguage = 'fr'): array
    {
        $productData['title'] = $this->translate($productData['title'] ?? '', $targetLanguage);
        $productData['description'] = $this->translate($productData['description'] ?? '', $targetLanguage);
        $productData['customer_says'] = $this->translate($productData['customer_says'] ?? '', $targetLanguage);
        $productData['features'] = array_map(fn ($feature) => $this->translate($feature, $targetLanguage), $productData['features'] ?? []);

        return $productData;
    }

    /**
     * Translates every human-readable string of a scraped product (title, description, brand excluded,
     * customer feedback, features, details type/value and variant values) into the target language.
     */
    public function translateScrapedProduct(array $productData, string $targetLanguage = 'fr'): array
    {
        $productData = $this->translateProductData($productData, $targetLanguage);

        $details = $productData['product_details'] ?? null;
        if (is_array($details)) {
            foreach ($details as $key => $detail) {
                if (!is_array($detail) || 'brand' === ($detail['type'] ?? null)) {
                    continue;
                }
                $details[$key] = [
                    'type' => $this->translate((string) ($detail['type'] ?? ''), $targetLanguage),
                    'value' => $this->translate((string) ($detail['value'] ?? ''), $targetLanguage),
                ];
            }
            $productData['product_details'] = $details;
        }

        $variants = $productData['variations'] ?? null;
        if (is_array($variants)) {
            $productData['variations'] = array_map(
                fn ($variant) => $this->translateValues($variant, $targetLanguage),
                array_slice($variants, 0, 10),
            );
        }

        return $productData;
    }

    /**
     * Recursively translates string values, leaving URLs, numeric strings and array keys untouched.
     */
    private function translateValues(mixed $value, string $targetLanguage): mixed
    {
        if (is_array($value)) {
            return array_map(fn ($item) => $this->translateValues($item, $targetLanguage), $value);
        }

        if (!is_string($value) || '' === trim($value) || is_numeric($value) || filter_var($value, FILTER_VALIDATE_URL)) {
            return $value;
        }

        return $this->translate($value, $targetLanguage);
    }
}
