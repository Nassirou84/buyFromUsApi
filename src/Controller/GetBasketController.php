<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\BasketRepository;
use App\Service\BasketService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Basket reads that include the basket "total". They keep the paths (and the list-of-baskets shape) of the
 * API Platform collection operations on Basket, which are routed here through their `routeName`.
 */
final class GetBasketController extends AbstractController
{
    /**
     * A customer's own basket. Admins may read any customer's.
     */
    #[Route('api/customer/{customerId}/baskets', name: 'app_get_customer_basket', requirements: ['customerId' => '\d+'], methods: ['GET'])]
    public function customerBasket(int $customerId, BasketService $basketService, BasketRepository $basketRepository): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['success' => false, 'error' => 'Utilisateur non authentifié.'], 401);
        }
        if ($user->getId() !== $customerId && !$this->isGranted('ROLE_ADMIN')) {
            return $this->json(['success' => false, 'error' => 'Accès refusé.'], 403);
        }

        $basket = $basketRepository->findOneBy(['user' => $customerId]);

        return new JsonResponse(null === $basket ? [] : [$basketService->normalizeWithTotal($basket)]);
    }

    /**
     * A basket by uid. A guest basket (no user) is readable by whoever holds its uid; a basket that belongs to
     * a user is only readable by that user (or an admin).
     */
    #[Route('api/basket/{uid}', name: 'app_get_basket_by_uid', methods: ['GET'])]
    public function basketByUid(string $uid, BasketService $basketService, BasketRepository $basketRepository): JsonResponse
    {
        $basket = $basketRepository->findOneBy(['uid' => $uid]);
        if (null === $basket) {
            return new JsonResponse([]);
        }

        $owner = $basket->getUser();
        if (null !== $owner) {
            $user = $this->getUser();
            if (!$user instanceof User) {
                return $this->json(['success' => false, 'error' => 'Utilisateur non authentifié.'], 401);
            }
            if ($user->getId() !== $owner->getId() && !$this->isGranted('ROLE_ADMIN')) {
                return $this->json(['success' => false, 'error' => 'Accès refusé.'], 403);
            }
        }

        return new JsonResponse([$basketService->normalizeWithTotal($basket)]);
    }
}
