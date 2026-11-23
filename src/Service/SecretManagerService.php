<?php

namespace App\Service;

use Google\Cloud\SecretManager\V1\AccessSecretVersionRequest;
use Google\Cloud\SecretManager\V1\Client\SecretManagerServiceClient;

class SecretManagerService
{
  private const CACHE_TTL_SECONDS = 180;

  private SecretManagerServiceClient $client;

  /** @var array<string, array{value: string, expiresAt: int}> */
  private array $cache = [];

  public function __construct(
    private string $projectId,
    private string $credentialsPath
  ) {
    $this->client = new SecretManagerServiceClient([
      'credentials' => $this->credentialsPath,
    ]);
  }

  public function getSecret(string $secretId): string
  {
    $now = time();

    if (isset($this->cache[$secretId]) && $this->cache[$secretId]['expiresAt'] > $now) {
      return $this->cache[$secretId]['value'];
    }

    $name = $this->client->secretVersionName($this->projectId, $secretId, 'latest');
    $request = (new AccessSecretVersionRequest())->setName($name);
    $response = $this->client->accessSecretVersion($request);
    $value = $response->getPayload()->getData();

    $this->cache[$secretId] = [
      'value' => $value,
      'expiresAt' => $now + self::CACHE_TTL_SECONDS,
    ];

    return $value;
  }
}