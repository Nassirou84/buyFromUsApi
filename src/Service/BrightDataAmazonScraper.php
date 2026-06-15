<?php

namespace App\Service;

class BrightDataAmazonScraper
{
  private string $apiKey;
  private string $baseUrl = 'https://api.brightdata.com/datasets/v3';
  public function __construct(private string $brightDataApiKey)
  {
    $this->apiKey = $brightDataApiKey;
  }
  /**
   * Scrape a single Amazon product by URL
   * 
   * @param string $url Amazon product URL (e.g., https://www.amazon.com/dp/B0CHHSFMRL)
   * @return array|null Product data or null on failure
   */
  public function scrapeProduct(string $url): ?array
  {
    // Use synchronous scrape endpoint [citation:8]
    $ch = curl_init();

    $queryParams = http_build_query([
      'dataset_id' => 'gd_l7q7dkf244hwjntr0', // Amazon dataset ID [citation:6]
      'format' => 'json'
    ]);

    curl_setopt($ch, CURLOPT_URL, $this->baseUrl . '/scrape?' . $queryParams);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([['url' => $url]]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
      'Authorization: Bearer ' . $this->apiKey,
      'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60); // 1 minute timeout [citation:8]

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
      error_log("Bright Data API error: HTTP {$httpCode}");
      return null;
    }

    $data = json_decode($response, true);
    return $data[0] ?? null;
  }

  /**
   * Scrape multiple products in one request (up to 20 URLs) [citation:8]
   * 
   * @param array $urls Array of Amazon product URLs
   * @return array|null Array of product data or null on failure
   */
  public function scrapeMultipleProducts(array $urls): ?array
  {
    if (count($urls) > 20) {
      throw new InvalidArgumentException('Maximum 20 URLs per request for synchronous scraping');
    }

    $ch = curl_init();

    $queryParams = http_build_query([
      'dataset_id' => 'gd_l7q7dkf244hwjntr0',
      'format' => 'json'
    ]);

    $payload = array_map(fn($url) => ['url' => $url], $urls);

    curl_setopt($ch, CURLOPT_URL, $this->baseUrl . '/scrape?' . $queryParams);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
      'Authorization: Bearer ' . $this->apiKey,
      'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);

    $response = curl_exec($ch);
    curl_close($ch);

    return json_decode($response, true);
  }

  /**
   * Search Amazon products by keyword
   * 
   * @param string $keyword Search term
   * @param string|null $zipcode Optional ZIP code for location-based results
   * @return array|null Search results
   */
  public function searchByKeyword(string $keyword, ?string $zipcode = null): ?array
  {
    $ch = curl_init();

    $payload = [['keyword' => $keyword]];
    if ($zipcode) {
      $payload[0]['zipcode'] = $zipcode;
    }

    $queryParams = http_build_query([
      'dataset_id' => 'gd_l7q7dkf244hwjntr0', // Amazon dataset ID [citation:6]
      'format' => 'json'
    ]);

    curl_setopt($ch, CURLOPT_URL, $this->baseUrl . '/scrape?' . $queryParams);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
      'Authorization: Bearer ' . $this->apiKey,
      'Content-Type: application/json'
    ]);

    $response = curl_exec($ch);
    curl_close($ch);

    return json_decode($response, true);
  }
}