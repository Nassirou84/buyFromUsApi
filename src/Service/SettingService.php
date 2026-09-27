<?php

declare(strict_types=1);

namespace App\Service;
use App\Repository\SettingRepository;

class SettingService
{
  public function __construct(
    private SettingRepository $settingRepository,
  ) {
  }

  public function getSetting(string $key): ?string
  {
    $setting = $this->settingRepository->findOneBy(['name' => $key]);
    if (!$setting) {
      return null;
    }
    return $setting->getValue();
  }
}