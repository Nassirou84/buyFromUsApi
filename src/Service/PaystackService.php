<?php

declare(strict_types=1);

namespace App\Service;

use App\Service\SecretManagerService;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class PaystackService
{
  private const BASE_URL = 'https://api.paystack.co';
  // Paystack expects the amount in the currency subunit and divides by 100 for
  // display (e.g. 63616 XOF must be sent as 6_361_600 to show "63616").
  private const SUBUNIT_MULTIPLIER = 100;

  public function __construct(
    private HttpClientInterface $httpClient,
    private SecretManagerService $secretManager,
    private OrderService $orderService
  ) {
  }

  /**
   * Initializes a Paystack transaction and returns
   * ['authorization_url' => ..., 'access_code' => ..., 'reference' => ...].
   *
   * @param float       $amount   Authoritative order amount (main unit, e.g. XOF)
   * @param string[]    $channels e.g. ['card'] or ['mobile_money']
   */
  public function initializeTransaction(
    string $email,
    float $amount,
    string $reference,
    string $orderUid,
    string $currency = 'XOF',
    bool $saveCard = false,
    array $channels = [],
    ?string $callbackUrl = null
  ): array {
    $secretKey = $this->secretManager->getSecret('paystack_test_secret_key');

    $payload = [
      'email' => $email,
      'amount' => (int) ($amount * self::SUBUNIT_MULTIPLIER),
      'reference' => $reference,
      'currency' => $currency,
      'metadata' => [
        'order_uid' => $orderUid,
        // Cards only — mobile money has no reusable charge token to store.
        'save_card' => $saveCard,
      ],
    ];

    if (!empty($channels)) {
      $payload['channels'] = $channels;
    }

    if ($callbackUrl !== null) {
      $payload['callback_url'] = $callbackUrl;
    }

    $response = $this->httpClient->request(
      'POST',
      self::BASE_URL . '/transaction/initialize',
      [
        'headers' => [
          'Authorization' => 'Bearer ' . $secretKey,
          'Content-Type' => 'application/json',
        ],
        'json' => $payload,
      ]
    );

    $data = json_decode($response->getContent(), true);

    if (($response->getStatusCode() !== 200) || empty($data['status'])) {
      throw new \RuntimeException('Failed to initialize Paystack transaction: ' . ($data['message'] ?? 'Unknown error'));
    }

    return $data['data'];
  }

  /**
   * Charges a previously saved card `authorization_code` directly, without a
   * redirect/checkout window. Used when a customer picks a stored card at
   * checkout: the reusable token is decrypted by the caller and passed here, and
   * Paystack settles the charge server-to-server. Mobile money has no reusable
   * charge token, so this is for cards only.
   *
   * Returns the Paystack transaction `data` (same shape as verifyTransaction),
   * whose `status` is `success` when the card was charged. Throws when the API
   * call itself fails (network/auth/validation), not when the card is declined
   * — a decline comes back as data with a non-`success` status.
   *
   * @return array<string, mixed>
   */
  public function chargeAuthorization(
    string $authorizationCode,
    string $email,
    float $amount,
    string $reference,
    string $orderUid,
    string $currency = 'XOF'
  ): array {
    $secretKey = $this->secretManager->getSecret('paystack_test_secret_key');

    $payload = [
      'authorization_code' => $authorizationCode,
      'email' => $email,
      'amount' => (int) ($amount * self::SUBUNIT_MULTIPLIER),
      'reference' => $reference,
      'currency' => $currency,
      'metadata' => [
        'order_uid' => $orderUid,
        // The method is already stored; never re-save on a charge of a saved card.
        'save_card' => false,
      ],
    ];

    $response = $this->httpClient->request(
      'POST',
      self::BASE_URL . '/transaction/charge_authorization',
      [
        'headers' => [
          'Authorization' => 'Bearer ' . $secretKey,
          'Content-Type' => 'application/json',
        ],
        'json' => $payload,
      ]
    );

    $data = json_decode($response->getContent(), true);

    if (($response->getStatusCode() !== 200) || empty($data['status'])) {
      throw new \RuntimeException('Failed to charge Paystack authorization: ' . ($data['message'] ?? 'Unknown error'));
    }

    return $data['data'];
  }

  public function verifyTransaction(string $reference): array
  {
    $secretKey = $this->secretManager->getSecret('paystack_test_secret_key');

    $response = $this->httpClient->request(
      'GET',
      self::BASE_URL . '/transaction/verify/' . $reference,
      [
        'headers' => [
          'Authorization' => 'Bearer ' . $secretKey,
          'Content-Type' => 'application/json',
        ],
      ]
    );

    $data = json_decode($response->getContent(), true);

    if (($response->getStatusCode() !== 200) || empty($data['status'])) {
      $orderUid = $data['data']['metadata']['order_uid'] ?? null;
      if ($orderUid !== null) {
        $this->orderService->markOrderAsFailed($orderUid);
      }

      throw new \RuntimeException('Failed to verify Paystack transaction for order UID ' . ($orderUid ?? 'unknown') . ': ' . ($data['message'] ?? 'Unknown error'));
    }

    return $data['data'];
  }
}