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

  public function encryptElement(string $secret)
  {
    $encryptionKey = $this->getSecret('encryption_key');

    $encrypted = openssl_encrypt($secret, 'aes-256-cbc', $encryptionKey, 0, substr($encryptionKey, 0, 16));
    return $encrypted;
  }

  public function decryptElement(string $encrypted): string
  {
    $encryptionKey = $this->getSecret('encryption_key');

    $decrypted = openssl_decrypt($encrypted, 'aes-256-cbc', $encryptionKey, 0, substr($encryptionKey, 0, 16));
    return $decrypted;
  }
}