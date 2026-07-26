<?php

namespace App\Service;

use App\Repository\OrderRepository;
use App\Repository\ShoppingRequestRepository;
use Symfony\Component\String\Slugger\SluggerInterface;

class UniqUidGenerator
{
  public function __construct(
    private SluggerInterface $slugger,
    private OrderRepository $orderRepository,
    private ShoppingRequestRepository $shoppingRequestRepository
  ) {
  }

  public function generateUniqueShoppingRequestUid(): string
  {
    do {
      $uid = $this->slugger->slug('R-' . substr(uniqid(), -5));
    } while ($this->orderRepository->findOneBy(['uid' => $uid]) || $this->shoppingRequestRepository->findOneBy(['uid' => $uid]));

    return $uid;
  }

  public function generateUniqueOrderUid(): string
  {
    do {
      $uid = $this->slugger->slug('O-' . substr(uniqid(), -5));
    } while ($this->orderRepository->findOneBy(['uid' => $uid]) || $this->shoppingRequestRepository->findOneBy(['uid' => $uid]));

    return $uid;
  }
}