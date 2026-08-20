<?php

declare(strict_types=1);

namespace App\Service;

use Exception;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class ExchangeRateApiService
{
    private HttpClientInterface $httpClient;
    private string $apiKey;
    private string $baseUrl = 'https://v6.exchangerate-api.com/v6/';

    public function __construct(HttpClientInterface $httpClient, string $exchangeRateApiKey)
    {
        $this->httpClient = $httpClient;
        $this->apiKey = $exchangeRateApiKey;
    }

    public function updateRates()
    {
        try {
            $response = $this->httpClient->request('GET', $this->baseUrl . $this->apiKey . '/latest/USD');
            $data = json_decode($response->getContent(), true);

            $rates = [
                'xof' => ceil($data['conversion_rates']['XOF']),
                'gnf' => ceil($data['conversion_rates']['GNF']),
            ];

            file_put_contents(__DIR__ . '/../../public/data/currency_rate.json', json_encode($rates));
        } catch (Exception $e) {
            // Handle the exception, e.g., log the error
            return false;
        }
    }
}
