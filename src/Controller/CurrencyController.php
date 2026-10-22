<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\CurrencyProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class CurrencyController extends AbstractController
{
    /**
     * Public: the currency every price in the API is expressed in. The storefront refetches
     * this (and the prices) to follow a currency change made in the admin settings.
     */
    #[Route('api/currency', name: 'app_currency', methods: ['GET'])]
    public function __invoke(CurrencyProvider $currencyProvider): JsonResponse
    {
        $response = $this->json([
            'currency' => strtoupper($currencyProvider->getCurrency()),
            'rate' => $currencyProvider->getRate(),
        ]);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
