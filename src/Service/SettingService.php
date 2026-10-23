<?php

declare(strict_types=1);

namespace App\Service;
use App\Repository\SettingRepository;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Reads settings through the "settings.cache" pool. The whole (small) table is cached under one
 * key and dropped by SettingCacheInvalidator whenever a Setting is written.
 */
class SettingService
{
  public const CACHE_KEY = 'settings.all';

  public function __construct(
    private SettingRepository $settingRepository,
    private CacheInterface $settingsCache,
  ) {
  }

  public function getSetting(string $key): ?string
  {
    foreach ($this->all() as $setting) {
      if ($setting['name'] === $key) {
        return $setting['value'];
      }
    }

    return null;
  }

  /**
   * Settings of the given scopes as [scope => [name => value]]. Every requested scope is
   * present in the result, even when it has no setting.
   *
   * @param list<string> $scopes
   *
   * @return array<string, array<string, string|null>>
   */
  public function getByScopes(array $scopes): array
  {
    $grouped = array_fill_keys($scopes, []);

    foreach ($this->all() as $setting) {
      if (isset($grouped[$setting['scope']])) {
        $grouped[$setting['scope']][$setting['name']] = $setting['value'];
      }
    }

    return $grouped;
  }

  public function clearCache(): void
  {
    $this->settingsCache->delete(self::CACHE_KEY);
  }

  /**
   * @return list<array{name: string|null, value: string|null, scope: string|null}>
   */
  private function all(): array
  {
    return $this->settingsCache->get(self::CACHE_KEY, function (ItemInterface $item): array {
      $rows = [];
      foreach ($this->settingRepository->findBy([], ['name' => 'ASC']) as $setting) {
        $rows[] = [
          'name' => $setting->getName(),
          'value' => $setting->getValue(),
          'scope' => $setting->getScope(),
        ];
      }

      return $rows;
    });
  }
}
