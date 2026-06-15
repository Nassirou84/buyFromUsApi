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
}