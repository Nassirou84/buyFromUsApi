<?php

namespace App\Service;

class CurrencyConverter
{
  private float $rate;

  public function __construct()
  {
    $filePath = __DIR__ . '/../../public/data/usd_to_xof.json';
    if (file_exists($filePath)) {
      $data = json_decode(file_get_contents($filePath), true);
      $this->rate = $data ?? 600;
    } else {
      $this->rate = 600;
    }
  }

  public function convertUSDToXOF($amount): float
  {
    return $amount * $this->rate;
  }
}