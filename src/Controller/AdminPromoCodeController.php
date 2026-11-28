<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\PromoCodeRepository;
use App\Service\PromoCodeService;
use DateTimeInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class AdminPromoCodeController extends AbstractController
{
    /**
     * Access: ROLE_ADMIN only. Promo codes cannot be edited or deleted; this is the only way to end one.
     */
    #[Route('api/admin/promo_codes/{id}/terminate', name: 'app_admin_promo_code_terminate', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function terminate(
        int $id,
        PromoCodeRepository $promoCodeRepository,
        PromoCodeService $promoCodeService,
    ): JsonResponse {
        $promoCode = $promoCodeRepository->find($id);
        if (!$promoCode) {
            return new JsonResponse(['error' => 'promo_code_not_found'], JsonResponse::HTTP_NOT_FOUND);
        }

        $promoCodeService->terminate($promoCode);

        return new JsonResponse([
            'success' => true,
            'id' => $promoCode->getId(),
            'isActive' => $promoCode->isActive(),
            'expiresAt' => $promoCode->getExpiresAt()?->format(DateTimeInterface::ATOM),
        ]);
    }
}
