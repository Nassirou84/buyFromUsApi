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

  /**
   * Converts an amount Paystack reports (currency subunit, e.g. 5728500) back to the
   * main unit we store (57285). Null when the value isn't numeric.
   */
  public static function fromSubunit(mixed $value): ?int
  {
    return is_numeric($value) ? (int) round(((float) $value) / self::SUBUNIT_MULTIPLIER) : null;
  }

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

    // getContent(false) reads the body WITHOUT throwing on a 4xx/5xx, so we can
    // surface Paystack's own `message` (e.g. "Currency not supported") instead of
    // a generic transport exception. getStatusCode() never throws.
    $data = json_decode($response->getContent(false), true);

    if ((200 !== $response->getStatusCode()) || empty($data['status'])) {
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

    $data = json_decode($response->getContent(false), true);

    if ((200 !== $response->getStatusCode()) || empty($data['status'])) {
      throw new \RuntimeException('Failed to charge Paystack authorization: ' . ($data['message'] ?? 'Unknown error'));
    }

    return $data['data'];
  }

  /**
   * Requests a (partial or full) refund of a settled transaction.
   * Returns the Paystack refund `data` (id, status, amount, ...).
   *
   * The two failure modes are kept apart because they need opposite handling:
   * - \DomainException: Paystack answered and refused (4xx). Nothing was refunded; safe to retry.
   * - \RuntimeException / TransportExceptionInterface: timeout, network error or 5xx.
   *   The refund may or may not exist; it must be reconciled before retrying.
   *
   * @param int $amount Amount in the main currency unit (e.g. XOF)
   *
   * @return array<string, mixed>
   *
   * @throws \Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface
   */
  public function refund(
    string $transactionReference,
    int $amount,
    string $currency,
    ?string $merchantNote = null,
    ?string $customerNote = null
  ): array {
    $secretKey = $this->secretManager->getSecret('paystack_test_secret_key');

    $payload = [
      'transaction' => $transactionReference,
      'amount' => $amount * self::SUBUNIT_MULTIPLIER,
      'currency' => $currency,
    ];
    if ($merchantNote !== null) {
      $payload['merchant_note'] = mb_substr($merchantNote, 0, 255);
    }
    if ($customerNote !== null) {
      $payload['customer_note'] = mb_substr($customerNote, 0, 255);
    }

    $response = $this->httpClient->request(
      'POST',
      self::BASE_URL . '/refund',
      [
        'headers' => [
          'Authorization' => 'Bearer ' . $secretKey,
          'Content-Type' => 'application/json',
        ],
        'json' => $payload,
      ]
    );

    $statusCode = $response->getStatusCode();
    $data = json_decode($response->getContent(false), true);
    $message = is_array($data) ? ($data['message'] ?? 'Unknown error') : 'Invalid response';

    if ($statusCode >= 400 && $statusCode < 500) {
      throw new \DomainException('Paystack refused the refund: ' . $message);
    }

    if (!in_array($statusCode, [200, 201], true) || !is_array($data) || empty($data['status']) || !is_array($data['data'] ?? null)) {
      throw new \RuntimeException('Paystack refund outcome unknown (HTTP ' . $statusCode . '): ' . $message);
    }

    return $data['data'];
  }

  /**
   * Fetches a refund by its Paystack refund id (GET /refund/{id}).
   * Returns the refund `data` (id, status, amount in subunit, transaction_reference, ...).
   *
   * @return array<string, mixed>
   *
   * @throws \RuntimeException when Paystack doesn't return the refund
   * @throws \Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface
   */
  public function fetchRefund(string $refundId): array
  {
    $data = $this->getJson('/refund/' . rawurlencode($refundId));

    if (!is_array($data['data'] ?? null)) {
      throw new \RuntimeException('Paystack refund ' . $refundId . ' not returned');
    }

    return $data['data'];
  }

  /**
   * Lists refunds created in a date range (GET /refund). Paystack can't filter this
   * endpoint by transaction, so callers match on transaction_reference themselves.
   *
   * @return array{data: list<array<string, mixed>>, pageCount: int}
   *
   * @throws \RuntimeException when the list can't be read
   * @throws \Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface
   */
  public function listRefunds(\DateTimeInterface $from, ?\DateTimeInterface $to = null, int $page = 1, int $perPage = 100): array
  {
    $query = [
      'from' => $from->format(\DateTimeInterface::ATOM),
      'page' => $page,
      'perPage' => $perPage,
    ];
    if ($to !== null) {
      $query['to'] = $to->format(\DateTimeInterface::ATOM);
    }

    $data = $this->getJson('/refund', $query);

    if (!is_array($data['data'] ?? null)) {
      throw new \RuntimeException('Paystack refund list not returned');
    }

    return [
      'data' => array_values(array_filter($data['data'], 'is_array')),
      'pageCount' => (int) ($data['meta']['pageCount'] ?? 1),
    ];
  }

  /**
   * Authenticated GET on the Paystack API; throws unless it answered 200 with status true.
   *
   * @param array<string, mixed> $query
   *
   * @return array<string, mixed>
   */
  private function getJson(string $path, array $query = []): array
  {
    $secretKey = $this->secretManager->getSecret('paystack_test_secret_key');

    $response = $this->httpClient->request('GET', self::BASE_URL . $path, [
      'headers' => [
        'Authorization' => 'Bearer ' . $secretKey,
        'Content-Type' => 'application/json',
      ],
      'query' => $query,
    ]);

    $statusCode = $response->getStatusCode();
    $data = json_decode($response->getContent(false), true);

    if (200 !== $statusCode || !is_array($data) || empty($data['status'])) {
      $message = is_array($data) ? ($data['message'] ?? 'Unknown error') : 'Invalid response';
      throw new \RuntimeException('Paystack GET ' . $path . ' failed (HTTP ' . $statusCode . '): ' . $message);
    }

    return $data;
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

    $data = json_decode($response->getContent(false), true);

    if ((200 !== $response->getStatusCode()) || empty($data['status'])) {
      $orderUid = $data['data']['metadata']['order_uid'] ?? null;
      if ($orderUid !== null) {
        $this->orderService->markOrderAsFailed($orderUid);
      }

      throw new \RuntimeException('Failed to verify Paystack transaction for order UID ' . ($orderUid ?? 'unknown') . ': ' . ($data['message'] ?? 'Unknown error'));
    }

    return $data['data'];
  }
}