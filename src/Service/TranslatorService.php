<?php

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
    $productData['features'] = array_map(fn($feature) => $this->translate($feature, $targetLanguage), $productData['features'] ?? []);

    return $productData;
  }
}