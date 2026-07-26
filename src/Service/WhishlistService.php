<?php

namespace App\Service;

use App\Entity\Wishlist;
use App\Repository\WishlistRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

class WhishlistService
{
  public function __construct(
    private WishlistRepository $wishlistRepository,
    private EntityManagerInterface $entityManagerInterface,
    private TokenStorageInterface $tokenService,
  ) {
  }

  public function createWishlist(Wishlist $wishlist): Wishlist
  {
    if (!$this->tokenService->getToken()) {
      throw new \Exception('Utilisateur non authentifié.');
    }
    $user = $this->tokenService->getToken()->getUser();
    $existingWishlist = $this->wishlistRepository->findOneBy([
      'user' => $user,
      'product' => $wishlist->getProduct()
    ]);
    if ($existingWishlist) {
      throw new \Exception('Votre produit est déjà dans votre liste de souhaits.');
    }
    $product = $wishlist->getProduct();
    if (!$product) {
      throw new \Exception('Produit non trouvé.');
    }
    $wishlist->setUser($user);
    $wishlist->setCreatedAt(new \DateTime());
    $wishlist->setUpdatedAt(new \DateTime());
    $wishlist->setPriceAtAdd((int) $product->getActualPrice());
    $this->entityManagerInterface->persist($wishlist);
    $this->entityManagerInterface->flush();
    return $wishlist;
  }
}