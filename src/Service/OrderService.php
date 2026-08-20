<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Order;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

final class OrderService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private TokenStorageInterface $tokenService,
        private UniqUidGenerator $uniqUidGenerator,
    ) {
    }

    public function createOrder(Order $order): Order
    {
        if (!$this->tokenService->getToken()) {
            throw new Exception('Utilisateur non authentifié.');
        }
        $currentUser = $this->tokenService->getToken()->getUser();
        if (!$currentUser instanceof \App\Entity\User) {
            throw new Exception('Utilisateur non authentifié.');
        }
        $order->setCustomer($currentUser);
        $order->setUid($this->uniqUidGenerator->generateUniqueUid(Order::class));

        $this->entityManager->persist($order);
        $this->entityManager->flush();

        return $order;
    }

    public function cancelOrder(Order $order): Order
    {
        if (Order::STATUS_CANCELLED === $order->getStatus()) {
            throw new Exception('Order is already cancelled');
        }
        $user = $this->tokenService->getToken()->getUser();
        if ($order->getCustomer() !== $user) {
            throw new Exception('You are not authorized to cancel this order');
        }
        $order->setStatus(Order::STATUS_CANCELLED);
        $this->entityManager->persist($order);
        $this->entityManager->flush();

        return $order;
    }
}
