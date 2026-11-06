<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\BasketRepository;
use App\Service\PromoCodeService;
use Exception;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

final class CheckPromoCodeController extends AbstractController
{
    #[Route(name: 'api_check_promo_code', path: 'api/promo_codes/check/{promoCode}')]
    public function checkPromoCode(
        string $promoCode,
        BasketRepository $basketRepository,
        PromoCodeService $promoCodeService,
        #[Target('promo_code')]
        RateLimiterFactoryInterface $promoCodeLimiter,
    ): JsonResponse {
        $user = $this->getUser();
        if (!$user) {
            return new JsonResponse(['error' => 'to_use_promo_code_you_must_be_logged_in'], 401);
        }

        // Throttle per user to stop enumeration of valid promo codes.
        if (!$promoCodeLimiter->create('user:' . $user->getUserIdentifier())->consume(1)->isAccepted()) {
            return new JsonResponse(['error' => 'too_many_requests'], 429);
        }

        $basket = $basketRepository->findOneBy(['user' => $user]);

        try {
            $isApplicable = $promoCodeService->checkPromoCode($promoCode, $basket);
            if (!$isApplicable) {
                throw new Exception('promo_code_not_applicable');
            }

            $amountAfterDiscount = $promoCodeService->amountAfterDiscount($promoCode, $basket);
            $discountedAmount = $promoCodeService->discountedAmount($promoCode, $basket);

            return new JsonResponse([
                'success' => true,
                'amountAfterDiscount' => $amountAfterDiscount,
                'discountedAmount' => $discountedAmount,
            ]);
        } catch (Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage(),
            ], 400);
        }
    }
}
