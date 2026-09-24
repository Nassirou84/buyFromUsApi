<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use App\Repository\PromoCodeRepository;
use Symfony\Component\Routing\Attribute\Route;

final class GetAvailableDiscountController extends AbstractController
{
    #[Route('api/available-discount', name: 'app_get_available_discount', methods: ['GET'])]
    public function index(
        PromoCodeRepository $promoCodeRepository
    ): JsonResponse {
        $availableDiscount = $promoCodeRepository->findAllUsersDiscount();
        return $this->json([
            'availableDiscount' => $availableDiscount,
        ]);
    }
}