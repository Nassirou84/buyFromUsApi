<?php

namespace App\Service;
use App\Entity\Basket;
use App\Entity\BasketItem;
use App\Entity\Product;
use App\Entity\Wishlist;
use App\Repository\BasketItemRepository;
use App\Repository\BasketRepository;
use App\Service\UniqUidGenerator;
use Doctrine\ORM\EntityManagerInterface;

class GuestBasketService
{
  public function __construct(
    private UniqUidGenerator $uniqUidGenerator,
    private BasketRepository $basketRepository,
    private EntityManagerInterface $entityManager,
    private BasketItemRepository $basketItemRepository,
  ) {
  }

  public function createOrRetrieveBasket(
    ?string $basketUid = null
  ) {
    return $basketUid ? $this->retrieveBasket($basketUid) : $this->createBasketAndPersist();
  }

  private function retrieveBasket(string $basketUid): Basket
  {
    $basket = $this->basketRepository->findOneBy(['uid' => $basketUid]);
    if (!$basket) {
      return $this->createBasketAndPersist();
    }
    return $basket;
  }

  private function createBasketAndPersist(): Basket
  {
    $basketUid = $this->uniqUidGenerator->generateUniqueUid(
      Basket::class
    );
    $basket = new Basket();

    $basket->setUid($basketUid);
    $basket->setCreatedAt(new \DateTimeImmutable());

    $this->entityManager->persist($basket);
    $this->entityManager->flush();

    return $basket;
  }


  public function addToBasket(Product $product, ?string $basketUid, int $quantity = 1, ?string $variant = null): BasketItem
  {
    $basket = $this->retrieveBasket($basketUid);

    $basketItem = new BasketItem();
    $basketItem->setProduct($product);
    $basketItem->setVariant($variant);
    $basketItem->setPriceAtAdd($product->getActualPrice());
    $basketItem->setBasket($basket);
    if ($basket->getBasketItems()->contains($basketItem)) {
      $existingItem = $this->basketItemRepository->findOneBy([
        'basket' => $basket,
        'product' => $product,
      ]);

      if ($existingItem) {
        $existingItem->setVariant($variant);
        $existingItem->setQuantity($existingItem->getQuantity() + $quantity);
        $this->entityManager->persist($existingItem);
        $this->entityManager->flush();

        return $existingItem;
      }
    }
    $basketItem->setQuantity($quantity);
    $this->entityManager->persist($basketItem);
    $this->entityManager->flush();

    return $basketItem;
  }

  public function removeFromBasket(?string $basketUid, BasketItem $basketItem): void
  {
    $basket = $this->retrieveBasket($basketUid);
    if ($basket->getBasketItems()->contains($basketItem)) {
      $this->entityManager->remove($basketItem);
      $this->entityManager->flush();
    }
  }

  public function updateBasketItemQuantity(?string $basketUid, BasketItem $basketItem, int $newQuantity): BasketItem
  {
    $basket = $this->retrieveBasket($basketUid);
    if ($basket->getBasketItems()->contains($basketItem)) {
      $basketItem->setQuantity($newQuantity);
      $this->entityManager->persist($basketItem);
      $this->entityManager->flush();
    }

    return $basketItem;
  }

  public function clearBasket(?string $basketUid): void
  {
    $basket = $this->retrieveBasket($basketUid);
    foreach ($basket->getBasketItems() as $basketItem) {
      $this->entityManager->remove($basketItem);
    }
    $this->entityManager->flush();
  }

  public function moveWishlistToBasket(?string $basketUid, Wishlist $wishlist): void
  {
    $productInWishlist = $wishlist->getProduct();
    $this->addToBasket($productInWishlist, $basketUid, 1, null);
    $this->entityManager->remove($wishlist);
    $this->entityManager->flush();
  }
}