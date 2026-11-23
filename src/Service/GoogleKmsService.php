<?php

namespace App\Service;

use Google\Cloud\Kms\V1\Client\KeyManagementServiceClient;
use Google\Cloud\Kms\V1\DecryptRequest;
use Google\Cloud\Kms\V1\EncryptRequest;

class GoogleKmsService
{
  private KeyManagementServiceClient $client;
  private string $cryptoKeyName;

  public function __construct(string $cryptoKey, string $credentialsPath)
  {
    // Initializes the KMS client using your Google service account credentials JSON file
    $this->client = new KeyManagementServiceClient([
      'credentials' => $credentialsPath,
    ]);
    $this->cryptoKeyName = $cryptoKey; // e.g., projects/ID/locations/global/keyRings/RING/cryptoKeys/KEY
  }

  /**
   * Encrypt plaintext natively using Google Cloud KMS (No local key required).
   *
   * NOTE: KMS encryption is non-deterministic — the same plaintext yields a
   * different ciphertext each call. Never use the output as a lookup key; use a
   * blind index (HMAC) for equality searches instead.
   *
   * $aad is the optional encryption context (Additional Authenticated Data). When
   * set, the exact same value MUST be supplied to decrypt, or KMS refuses — this
   * binds a ciphertext to its context (e.g. its owning user) so it cannot be moved
   * to another record and decrypted there.
   */
  public function encrypt(string $plaintext, ?string $aad = null): string
  {
    $request = (new EncryptRequest())
      ->setName($this->cryptoKeyName)
      ->setPlaintext($plaintext);
    if (null !== $aad) {
      $request->setAdditionalAuthenticatedData($aad);
    }
    $response = $this->client->encrypt($request);

    // Base64 encode the binary ciphertext so it can be safely stored in a database VARCHAR/TEXT column
    return base64_encode($response->getCiphertext());
  }

  /**
   * Decrypt ciphertext natively using Google Cloud KMS.
   *
   * $aad must match the encryption context the ciphertext was produced with
   * (or be null if none was used); KMS throws otherwise.
   */
  public function decrypt(string $base64Ciphertext, ?string $aad = null): string
  {
    $ciphertext = base64_decode($base64Ciphertext, true);
    if (false === $ciphertext) {
      throw new \InvalidArgumentException('Invalid base64 ciphertext passed to KMS decrypt.');
    }

    $request = (new DecryptRequest())
      ->setName($this->cryptoKeyName)
      ->setCiphertext($ciphertext);
    if (null !== $aad) {
      $request->setAdditionalAuthenticatedData($aad);
    }
    $response = $this->client->decrypt($request);

    return $response->getPlaintext();
  }

  /**
   * Close the pooled gRPC transport once, when the service is torn down. Closing
   * after every encrypt/decrypt (as before) left later calls in the same request
   * running against a closed client and defeated connection reuse.
   */
  public function __destruct()
  {
    try {
      $this->client->close();
    } catch (\Throwable $e) {
      // Nothing actionable while tearing down; never let it surface.
    }
  }
}
