<?php

declare(strict_types=1);

namespace App\Service;

use RuntimeException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class ScrapperService
{
  public const PASSKEY_HEADER = 'X-Scrapper-Passkey';

  private const TIMEOUT = 120;

  public function __construct(
    private readonly HttpClientInterface $httpClient,
    private readonly string $scrapperServiceUrl,
    private readonly string $scrapperPasskey,
  ) {
  }

  /**
   * Asks the scrapper microservice to scrape a single product page.
   *
   * @return array{status: int, data: array<string, mixed>} the scrapper's HTTP status and decoded JSON body
   *
   * @throws RuntimeException when the scrapper is unreachable or answers with something that is not JSON
   */
  public function scrape(string $url): array
  {
    try {
      $response = $this->httpClient->request('GET', rtrim($this->scrapperServiceUrl, '/') . '/api/scrapper', [
        'query' => ['url' => $url],
        'headers' => [
          'Accept' => 'application/json',
          self::PASSKEY_HEADER => $this->scrapperPasskey,
        ],
        'timeout' => self::TIMEOUT,
      ]);

      $status = $response->getStatusCode();
      $data = $response->toArray(false);
    } catch (ExceptionInterface $e) {
      throw new RuntimeException('Scrapper service unavailable: ' . $e->getMessage(), 0, $e);
    }

    return ['status' => $status, 'data' => $data];
  }
}