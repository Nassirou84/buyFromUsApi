<?php

namespace App\Service;

use App\Entity\Order;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

final class OrderService
{
  public function __construct(
    private EntityManagerInterface $entityManager,
    private TokenStorageInterface $tokenService,
  ) {
  }

  public function createOrder(Order $order): Order
  {
    if ($this->tokenService->getToken()) {
      $currentUser = $this->tokenService->getToken()->getUser();
      $order->setCustomer($currentUser);
    }
    $order->setUid('order-' . uniqid());

    $this->entityManager->persist($order);
    $this->entityManager->flush();
    return $order;
  }

  public function cancelOrder(Order $order): Order
  {
    //Implements add email queue
    $order->setStatus(Order::STATUS_CANCELLED);
    $this->entityManager->persist($order);
    $this->entityManager->flush();
    return $order;
  }
}