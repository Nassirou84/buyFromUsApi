<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Basket;
use App\Entity\BasketItem;
use App\Entity\User;
use App\Repository\BasketItemRepository;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

class BasketService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UniqUidGenerator $uniqUidGenerator,
        private BasketItemRepository $basketItemRepository,
        private NormalizerInterface $normalizer,
    ) {
    }

    /**
     * The "basket:read" representation of a basket (user or guest) plus its current "total".
     *
     * @return array<string, mixed>
     */
    public function normalizeWithTotal(Basket $basket): array
    {
        $normalized = $this->normalizer->normalize($basket, null, ['groups' => ['basket:read']]);
        $normalized['total'] = $basket->getTotalAmount();

        return $normalized;
    }

    public function createBasketForUser(User $user): Basket
    {
        $basket = new Basket();
        $basket->setUser($user);
        $basket->setUid($this->uniqUidGenerator->generateUniqueUid(Basket::class));
        $this->entityManager->persist($basket);
        $this->entityManager->flush();

        return $basket;
    }

    /**
     * @param array<string, mixed>|null $variant
     */
    public function addToBasket(User $user, $product, int $quantity = 1, ?array $variant = null): BasketItem
    {
        $basket = $user->getBasket();
        if (!$basket) {
            $basket = $this->createBasketForUser($user);
        }

        $existingItem = null;
        foreach ($this->basketItemRepository->findBy(['basket' => $basket, 'product' => $product]) as $candidate) {
            if ($candidate->isSameVariant($variant)) {
                $existingItem = $candidate;
                break;
            }
        }

        if ($existingItem) {
            $existingItem->setQuantity($existingItem->getQuantity() + $quantity);
            $this->entityManager->persist($existingItem);
            $this->entityManager->flush();

            return $existingItem;
        }

        $basketItem = new BasketItem();
        $basketItem->setProduct($product);
        $basketItem->setVariant($variant);
        $basketItem->setPriceAtAdd($product->getActualPriceForVariant($variant));
        $basketItem->setBasket($basket);
        $basketItem->setQuantity($quantity);
        $this->entityManager->persist($basketItem);
        $this->entityManager->flush();

        return $basketItem;
    }

    public function removeFromBasket(User $user, BasketItem $basketItem): void
    {
        $basket = $user->getBasket();
        if ($basket && $basket->getBasketItems()->contains($basketItem)) {
            $this->entityManager->remove($basketItem);
            $this->entityManager->flush();
        }
    }

    public function clearBasket(User $user): void
    {
        $basket = $user->getBasket();
        if ($basket) {
            foreach ($basket->getBasketItems() as $basketItem) {
                $this->entityManager->remove($basketItem);
            }
            $this->entityManager->flush();
        }
    }

    public function updateBasketItemQuantity(User $user, BasketItem $basketItem, int $quantity): BasketItem
    {
        $basket = $user->getBasket();
        if ($basket && $basket->getBasketItems()->contains($basketItem)) {
            if ($quantity <= 0) {
                $this->removeFromBasket($user, $basketItem);
            } else {
                $basketItem->setQuantity($quantity);
                $this->entityManager->persist($basketItem);
                $this->entityManager->flush();
            }
        } else {
            throw new Exception('Le panier de l\'utilisateur ne contient pas cet article.');
        }

        return $basketItem;
    }
}