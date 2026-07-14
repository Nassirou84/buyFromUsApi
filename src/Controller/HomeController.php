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
        $productData = $brightDataAmazonScraper->scrapeProduct('https://www.amazon.com/Google-Pixel-Smartphone-Assistant-Fast-Charging/dp/B0FFTRK635/ref=sr_1_1_sspa?crid=2XUZK83JRAF8A&dib=eyJ2IjoiMSJ9.J2DHLcRIkKP6zLt74HGwdbzEvhuQRVyH13B9JaSC3_61QNJu3eLChmkuCLBe8nmyn7Xoysb9jo_MUCMQe9c6XZ2QxjLXvdtNzSyACIEVwMSmo2ZaqPZMP9-CqYncEyfP4-peyrcArUdT2CLEYrt18iRyu4hzTCP6DbtUsBJyZmol-Cvt4r5L67F92bMnPBuu-wI649QUa8uzB0b40vANjpIhmFTjPM210SYk4KR-9zY.LI1vqNBgOYUvE_076UHOof7tx_48JtHywwGRz48fV2s&dib_tag=se&keywords=google%2Bpixel&qid=1783688970&sprefix=google%2Bpixel%2Caps%2C181&sr=8-1-spons&ufe=app_do%3Aamzn1.fos.0f891610-7b8b-4b91-8ba6-963fbc5b64cf&sp_csd=d2lkZ2V0TmFtZT1zcF9hdGY&th=1');

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