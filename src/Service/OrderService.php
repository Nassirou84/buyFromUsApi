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
    private UniqUidGenerator $uniqUidGenerator
  ) {
  }

  public function createOrder(Order $order): Order
  {
    if ($this->tokenService->getToken()) {
      $currentUser = $this->tokenService->getToken()->getUser();
      $order->setCustomer($currentUser);
    }
    $order->setUid($this->uniqUidGenerator->generateUniqueOrderUid());

    $this->entityManager->persist($order);
    $this->entityManager->flush();
    return $order;
  }

  public function cancelOrder(Order $order): Order
  {
    if ($order->getStatus() === Order::STATUS_CANCELLED) {
      throw new \Exception('Order is already cancelled');
    }
    $user = $this->tokenService->getToken()->getUser();
    if ($order->getCustomer() !== $user) {
      throw new \Exception('You are not authorized to cancel this order');
    }

    //Implements add email queue
    $order->setStatus(Order::STATUS_CANCELLED);
    $this->entityManager->persist($order);
    $this->entityManager->flush();
    return $order;
  }
}