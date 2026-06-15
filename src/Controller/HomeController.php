<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use App\Service\BrightDataAmazonScraper;
use App\Service\TranslatorService;

final class HomeController extends AbstractController
{
    #[Route('/home', name: 'app_home')]
    public function index(
        BrightDataAmazonScraper $brightDataAmazonScraper
    ): JsonResponse {
        $productData = $brightDataAmazonScraper->scrapeProduct('https://www.amazon.com/Apple-iPhone-Silicone-MagSafe-Control/dp/B0FQFPBQYB/ref=sr_1_1_sspa?crid=8WVU3HVP7Q0S&dib=eyJ2IjoiMSJ9.LstBpsRsz0tXmAmY0X2UD9ZVlL5x9eSE24ng8zCg2I8U3sQHxeEcnCpcVeLra7amPjhq6eRn5VHT0daU1zkPjm6l6BKejv1QbbS0L4IWwozV8t8_QFZEMxtRtUsvXRdVHXkWdx6l2P5H2ebKHhoJK1aoST81A9drvIlPy23yCAHFIbuCjIbQnoIp2CkKLLfJLLBtwmsojxdlFGgagEwKNi1dNcUdUg-2HA5mOVlYDtQ.AYdFTSZkoEnlDy6SzZWq8QvCRiBoQYsTbOnwJGYhAGg&dib_tag=se&keywords=iphone%2B17%2Bpro%2Bmax&qid=1781563248&sprefix=ipho%2Caps%2C185&sr=8-1-spons&sp_csd=d2lkZ2V0TmFtZT1zcF9hdGY&th=1');

        return $this->json([
            'product_data' => $productData,
        ]);
    }

    #[
        Route('/translate', name: 'app_translate')
    ]
    public function translate(
        TranslatorService $translatorService
    ): JsonResponse {
        $translatedText = $translatorService->translate('About this item DESIGNED BY APPLE — This Apple case is designed to fit iPhone 17 Pro Max CAMERA CONTROL — This case features a sapphire crystal coupled to a conductive layer to communicate finger movements to the Camera Control. LIGHTWEIGHT AND SMOOTH — Made from a 55 percent recycled silicone material, the case is lightweight and smooth to the touch, with a soft microfiber lining on the inside for even more protection. SCRATCH AND DROP PROTECTION — Not only does this case look great, but the raised edges protect your iPhone from scratches and drops. MAGSAFE CHARGING COMPATIBLE — With built-in magnets that align perfectly with your iPhone 17 Pro Max, this case offers a magical attach experience to other MagSafe accessories and is MagSafe charging compatible. › See more product details', 'fr');

        return $this->json([
            'translated_text' => $translatedText,
        ]);
    }
}