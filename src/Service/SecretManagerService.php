<?php

namespace App\Service;

use Google\Cloud\SecretManager\V1\AccessSecretVersionRequest;
use Google\Cloud\SecretManager\V1\Client\SecretManagerServiceClient;

class SecretManagerService
{
  private SecretManagerServiceClient $client;

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
    $name = $this->client->secretVersionName($this->projectId, $secretId, 'latest');
    $request = (new AccessSecretVersionRequest())->setName($name);
    $response = $this->client->accessSecretVersion($request);
    return $response->getPayload()->getData();
  }
}