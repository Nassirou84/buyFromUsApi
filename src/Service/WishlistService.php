<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Wishlist;
use App\Repository\WishlistRepository;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

class WishlistService
{
    public function __construct(
        private WishlistRepository $wishlistRepository,
        private EntityManagerInterface $entityManagerInterface,
        private TokenStorageInterface $tokenService,
        private BasketService $basketService,
    ) {
    }

    public function createWishlist(Wishlist $wishlist): Wishlist
    {
        if (!$this->tokenService->getToken()) {
            throw new Exception('Utilisateur non authentifié.');
        }
        $user = $this->tokenService->getToken()->getUser();
        if (!$user instanceof \App\Entity\User) {
            throw new Exception('Utilisateur non authentifié.');
        }
        $existingWishlist = $this->wishlistRepository->findOneBy([
            'user' => $user,
            'product' => $wishlist->getProduct(),
        ]);
        if ($existingWishlist) {
            throw new Exception('Votre produit est déjà dans votre liste de souhaits.');
        }
        $product = $wishlist->getProduct();
        if (!$product) {
            throw new Exception('Produit non trouvé.');
        }
        $wishlist->setUser($user);
        $wishlist->setCreatedAt(new DateTime());
        $wishlist->setUpdatedAt(new DateTime());
        $wishlist->setPriceAtAdd((int) $product->getActualPrice());
        $this->entityManagerInterface->persist($wishlist);
        $this->entityManagerInterface->flush();

        return $wishlist;
    }

    public function moveWishlistToBasket(Wishlist $wishlist): void
    {
        if (!$this->tokenService->getToken()) {
            throw new Exception('Utilisateur non authentifié.');
        }
        $user = $this->tokenService->getToken()->getUser();
        if ($wishlist->getUser() !== $user) {
            throw new Exception('Vous n\'êtes pas autorisé à effectuer cette action.');
        }
        $userCurrentBasket = $user->getBasket();
        if (!$userCurrentBasket) {
            $userCurrentBasket = $this->basketService->createBasketForUser($user);
        }

        $basketItem = $this->basketService->addToBasket($user, $wishlist->getProduct(), 1);
        $this->entityManagerInterface->persist($basketItem);
        $this->entityManagerInterface->remove($wishlist);
        $this->entityManagerInterface->flush();
    }

    public function removeProduct(int $productId): void
    {
        if (!$this->tokenService->getToken()) {
            throw new Exception('Utilisateur non authentifié.');
        }
        $user = $this->tokenService->getToken()->getUser();
        $wishlist = $this->wishlistRepository->findOneBy([
            'user' => $user,
            'product' => $productId,
        ]);
        if (!$wishlist) {
            throw new Exception('Produit non trouvé dans la liste de souhaits.');
        }
        if ($wishlist->getUser() !== $user) {
            throw new Exception('Vous n\'êtes pas autorisé à effectuer cette action.');
        }
        $this->entityManagerInterface->remove($wishlist);
        $this->entityManagerInterface->flush();
    }
}