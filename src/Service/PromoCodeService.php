<?php

namespace App\Service;

use App\Entity\Basket;
use App\Entity\PromoCode;
use App\Entity\PromoCodeUsage;
use App\Repository\PromoCodeRepository;
use App\Repository\PromoCodeUsageRepository;
use Doctrine\ORM\EntityManagerInterface;
use App\Repository\OrderRepository;


class PromoCodeService
{

  public function __construct(
    private PromoCodeRepository $promoCodeRepository,
    private PromoCodeUsageRepository $promoCodeUsageRepository,
    private OrderRepository $orderRepository,
    private EntityManagerInterface $entityManagerInterface
  ) {
  }

  public function markAsUsed(string $promoCode, Basket $basket): void
  {
    $promoCodeEntity = $this->promoCodeRepository->findOneBy(['code' => $promoCode]);
    if ($promoCodeEntity) {
      $promoCodeUsage = new PromoCodeUsage();
      $promoCodeUsage->setPromoCode($promoCodeEntity);
      $promoCodeUsage->setUser($basket->getUser());
      $this->entityManagerInterface->persist($promoCodeUsage);
      $this->entityManagerInterface->flush();
    }
  }

  public function checkPromoCode(string $promoCode, Basket $basket): bool
  {
    $promoCodeEntity = $this->promoCodeRepository->findOneBy(['code' => $promoCode]);
    if (!$promoCodeEntity) {
      throw new \Exception('promo_code_not_found');
    }
    if ($promoCodeEntity->getExpiresAt() < new \DateTime()) {
      throw new \Exception('promo_code_expired');
    }
    if ($this->promoCodeUsageRepository->hasUserUsedPromoCode($basket->getUser(), $promoCodeEntity)) {
      throw new \Exception('promo_code_already_used');
    }
    $isApplicable = $this->termsApplicable($promoCodeEntity, $basket);

    return $isApplicable;
  }

  public function termsApplicable($promoCodeEntity, Basket $basket): bool
  {
    $isApplicable = false;

    switch ($promoCodeEntity->getTerms()) {
      case PromoCode::APPLY_ALL_USERS:
        $isApplicable = true;
        break;
      case PromoCode::APPLY_SPECIFIC_USERS:
        $isApplicable = $this->specificUsersApplicable($promoCodeEntity, $basket);
        break;
      case PromoCode::APPLY_FIRST_TIME_PURCHASE:
        $isApplicable = $this->firstTimePurchaseApplicable($basket);
        break;
      case PromoCode::APPLY_MINIMUM_ORDER_AMOUNT:
        $isApplicable = $this->minimumOrderAmountApplicable($promoCodeEntity, $basket);
        break;
      case PromoCode::APPLY_TARGET_QUANTITY:
        $isApplicable = $this->targetQuantityApplicable($promoCodeEntity, $basket);
        break;
      case PromoCode::APPLY_TARGET_CATEGORY:
        $isApplicable = $this->targetCategoryApplicable($promoCodeEntity, $basket);
        break;
      default:
        $isApplicable = false;
    }
    return $isApplicable;
  }

  public function amountAfterDiscount(string $promoCode, Basket $basket): float
  {
    $promoCodeEntity = $this->promoCodeRepository->findOneBy(['code' => $promoCode]);
    if (!$promoCodeEntity) {
      return $basket->getTotalAmount();
    }
    $discountedAmount = $this->discountedAmount($promoCode, $basket);
    $totalAmount = $basket->getTotalAmount();
    return $totalAmount - $discountedAmount;
  }

  public function discountedAmount(string $promoCode, Basket $basket): float
  {
    $promoCodeEntity = $this->promoCodeRepository->findOneBy(['code' => $promoCode]);
    if (!$promoCodeEntity) {
      return 0.0;
    }
    $discount = $promoCodeEntity->getDiscount();
    $totalAmount = $basket->getTotalAmount();
    $discountedAmount = $totalAmount * ($discount / 100);
    return $discountedAmount;
  }

  public function specificUsersApplicable($promoCodeEntity, Basket $basket): bool
  {
    return $promoCodeEntity->getUser() === $basket->getUser();
  }

  public function firstTimePurchaseApplicable(Basket $basket): bool
  {
    $user = $basket->getUser();
    $previousOrders = $this->orderRepository->findBy(['customer' => $user]);
    return count($previousOrders) === 0;
  }

  public function minimumOrderAmountApplicable($promoCodeEntity, Basket $basket): bool
  {
    $minimumAmount = $promoCodeEntity->getTarget();
    return $basket->getTotalAmount() >= $minimumAmount;
  }

  public function targetQuantityApplicable($promoCodeEntity, Basket $basket): bool
  {
    $targetQuantity = (int) $promoCodeEntity->getTarget();
    $totalQuantity = 0;
    foreach ($basket->getBasketItems() as $basketItem) {
      $totalQuantity += $basketItem->getQuantity();
    }
    return $totalQuantity >= $targetQuantity;
  }

  public function targetCategoryApplicable($promoCodeEntity, Basket $basket): bool
  {
    $targetCategory = $promoCodeEntity->getTarget();
    foreach ($basket->getBasketItems() as $basketItem) {
      if ($basketItem->getProduct()->getCategory() === $targetCategory) {
        return true;
      }
    }
    return false;
  }
}