<?php

namespace App\Controller;

use App\Service\BrightDataAmazonScraper;
use App\Service\CurrencyConverter;
use App\Service\TranslatorService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController extends AbstractController
{
    #[Route('/api/home', name: 'app_home')]
    public function index(
        BrightDataAmazonScraper $brightDataAmazonScraper,
    ): JsonResponse {
        $productData = $brightDataAmazonScraper->scrapeProduct('https://www.amazon.com/Arabic-Keyboard-Sticker-Transparent-Computer/dp/B004LY7X92?th=1&psc=1&language=en_US&currency=USD');

        return $this->json([
            'product_data' => $productData,
        ]);
    }

    #[
        Route('/translate', name: 'app_translate')
    ]
    public function translate(
        TranslatorService $translatorService,
        CurrencyConverter $currencyConverter
    ): JsonResponse {
        $translatedText = $translatorService->translate('About this item DESIGNED BY APPLE — This Apple case is designed to fit iPhone 17 Pro Max CAMERA CONTROL — This case features a sapphire crystal coupled to a conductive layer to communicate finger movements to the Camera Control. LIGHTWEIGHT AND SMOOTH — Made from a 55 percent recycled silicone material, the case is lightweight and smooth to the touch, with a soft microfiber lining on the inside for even more protection. SCRATCH AND DROP PROTECTION — Not only does this case look great, but the raised edges protect your iPhone from scratches and drops. MAGSAFE CHARGING COMPATIBLE — With built-in magnets that align perfectly with your iPhone 17 Pro Max, this case offers a magical attach experience to other MagSafe accessories and is MagSafe charging compatible. › See more product details', 'fr');

        $price = $currencyConverter->convertUSDToXOF(1);

        return $this->json([
            'translated_text' => $translatedText,
            'price' => $price
        ]);
    }
}