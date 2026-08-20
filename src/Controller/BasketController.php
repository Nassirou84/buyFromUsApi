<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\BasketItemRepository;
use App\Repository\ProductRepository;
use App\Repository\WishlistRepository;
use App\Service\BasketService;
use App\Service\WishlistService;
use Exception;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

use function is_int;

#[Route('api/basket')]
final class BasketController extends AbstractController
{
    #[Route('/add', name: 'app_add_to_basket', methods: ['POST'])]
    public function addToBasket(
        Request $request,
        BasketService $basketService,
        ProductRepository $productRepository,
    ): JsonResponse {
        $user = $this->getUser();
        if (!$user instanceof \App\Entity\User) {
            return $this->json(['success' => false, 'error' => 'Utilisateur non authentifié.'], 401);
        }

        $params = json_decode($request->getContent(), true);
        $productId = $params['productId'] ?? null;
        if (!$productId) {
            return $this->json(['success' => false, 'error' => 'ID du produit manquant.'], 400);
        }
        // Get the product from the repository
        $product = $productRepository->find($productId);
        if (!$product) {
            return $this->json(['success' => false, 'error' => 'Produit non trouvé.'], 404);
        }

        $variantId = $params['variant'] ?? null;
        $quantity = $params['quantity'] ?? 1;

        $basketItem = $basketService->addToBasket($user, $product, $quantity, $variantId);

        return $this->json(['success' => true, 'message' => 'Ajouté au panier', 'basketItem' => $basketItem], 200);
    }

    #[Route('/remove/{basketItemId}', name: 'app_remove_from_basket', methods: ['POST'])]
    public function removeFromBasket(
        int $basketItemId,
        BasketService $basketService,
        BasketItemRepository $basketItemRepository,
    ): JsonResponse {
        $user = $this->getUser();
        if (!$user instanceof \App\Entity\User) {
            return $this->json(['success' => false, 'error' => 'Utilisateur non authentifié.'], 401);
        }
        $basketItem = $basketItemRepository->find($basketItemId);
        if (!$basketItem) {
            return $this->json(['success' => false, 'error' => 'Élément du panier non trouvé.'], 404);
        }

        try {
            $basketService->removeFromBasket($user, $basketItem);

            return $this->json(['success' => true, 'message' => 'Élément retiré du panier'], 200);
        } catch (Exception $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], 400);
        }
    }

    #[Route('/update-quantity/{basketItemId}', name: 'app_update_basket_item_quantity', methods: ['POST'])]
    public function updateBasketItemQuantity(
        int $basketItemId,
        BasketItemRepository $basketItemRepository,
        Request $request,
        BasketService $basketService,
    ): JsonResponse {
        $user = $this->getUser();
        if (!$user instanceof \App\Entity\User) {
            return $this->json(['success' => false, 'error' => 'Utilisateur non authentifié.'], 401);
        }
        $basketItem = $basketItemRepository->find($basketItemId);
        if (!$basketItem) {
            return $this->json(['success' => false, 'error' => 'Élément du panier non trouvé.'], 404);
        }

        $params = json_decode($request->getContent(), true);
        $newQuantity = $params['quantity'] ?? null;

        if (null === $newQuantity || !is_int($newQuantity) || $newQuantity < 1) {
            return $this->json(['success' => false, 'error' => 'Quantité invalide.'], 400);
        }

        try {
            $basketItem = $basketService->updateBasketItemQuantity($user, $basketItem, $newQuantity);

            return $this->json(['success' => true, 'message' => 'Quantité mise à jour', 'newQuantity' => $basketItem->getQuantity()], 200);
        } catch (Exception $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], 400);
        }
    }

    #[Route('/clear', name: 'app_clear_basket', methods: ['POST'])]
    public function clearBasket(BasketService $basketService): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof \App\Entity\User) {
            return $this->json(['success' => false, 'error' => 'Utilisateur non authentifié.'], 401);
        }

        try {
            $basketService->clearBasket($user);

            return $this->json(['success' => true, 'message' => 'Panier vidé avec succès'], 200);
        } catch (Exception $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], 400);
        }
    }

    #[Route('/move-to-basket/{wishlistId}', name: 'app_move_wishlist_to_basket', methods: ['POST'])]
    public function moveWishlistToBasket(
        int $wishlistId,
        WishlistService $wishlistService,
        WishlistRepository $wishlistRepository,
    ): JsonResponse {
        $user = $this->getUser();
        if (!$user instanceof \App\Entity\User) {
            return $this->json(['success' => false, 'error' => 'Utilisateur non authentifié.'], 401);
        }

        $wishlist = $wishlistRepository->find($wishlistId);
        if (!$wishlist) {
            return $this->json(['success' => false, 'error' => 'Élément de la liste de souhaits non trouvé.'], 404);
        }

        try {
            $wishlistService->moveWishlistToBasket($wishlist);

            return $this->json(['success' => true, 'message' => 'Élément déplacé vers le panier'], 200);
        } catch (Exception $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], 400);
        }
    }
}
