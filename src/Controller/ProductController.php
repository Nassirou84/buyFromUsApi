<?php

namespace App\Controller;
use App\Service\BrightDataAmazonScraper;
use App\Service\TranslatorService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('api/product')]
final class ProductController extends AbstractController
{
    #[Route('/scrape', name: 'app_product_scrape', methods: ['POST'])]
    public function scrape(
        Request $request,
        BrightDataAmazonScraper $brightDataAmazonScraper,
        TranslatorService $translatorService,
    ): JsonResponse {
        set_time_limit(120);
        $url = $request->getContent();
        $url = json_decode($url, true)['url'] ?? null;
        $response = new JsonResponse();
        if (!$url) {
            return new JsonResponse(['error' => 'URL parameter is missing'], 400);
        }
        $productData = $brightDataAmazonScraper->scrapeProduct($url);
        $productData = $translatorService->translateProductData
        ($productData);

        $productDetails = $productData['product_details'] ?? null;

        if (is_array($productDetails)) {
            foreach ($productDetails as $key => $detail) {
                if ($detail['type'] === 'brand') {
                    continue;
                }
                $productDetails[$key] = [
                    'type' => $translatorService->translate($detail['type'] ?? '', 'fr') ?? null,
                    'value' => $translatorService->translate($detail['value'] ?? '', 'fr') ?? null,
                ];
            }
        }

        $variants = $productData['variations'] ?? null;
        $variants = array_slice($variants ?? [], 0, 10);
        // if (is_array($variants)) {
        //     foreach ($variants as $key => $variant) {
        //         foreach ($variant as $key => $value) {
        //             if (!filter_var($value, FILTER_VALIDATE_URL)) {
        //                 $variant[$key] = $translatorService->translate($variant[$key] ?? '', 'fr') ?? null;
        //             }
        //         }
        //         $variants[$key] = $variant;
        //     }
        // }

        $response->setData([
            'title' => $productData['title'] ?? null,
            'description' => $productData['description'] ?? null,
            'seller' => $productData['seller_name'] ?? null,
            'usdPrice' => $productData['final_price'] ?? null,
            'brand' => $productData['brand'] ?? null,
            'customerSays' => $productData['customer_says'] ?? null,
            'features' => $productData['features'] ?? null,
            'variants' => $variants ?? null,
            'productDetails' => $productData['product_details'] ?? null,
            'scrappingUrl' => $productData['url'] ?? null,
            'isAvailable' => $productData['is_available'] ?? null,
        ]);
        return $response;
    }
}