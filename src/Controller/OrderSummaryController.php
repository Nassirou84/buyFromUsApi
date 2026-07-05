<?php

namespace App\Controller;

use App\Repository\OrderRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

final class OrderSummaryController extends AbstractController
{
    public function __invoke(
        TokenStorageInterface $tokenStorageInterface,
        OrderRepository $orderRepository
    ): JsonResponse {
        if (!$tokenStorageInterface->getToken()) {
            return new JsonResponse([]);
        }
        $user = $tokenStorageInterface->getToken()->getUser();
        $totalOrders = $orderRepository->count(['customer' => $user]);
        $activeOrders = $orderRepository->countActiveOrders($user);
        $totalSpent = $orderRepository->getTotalSpents($user);

        return new JsonResponse([
            'totalOrders' => $totalOrders,
            'activeOrders' => $activeOrders,
            'totalSpent' => $totalSpent
        ]);
    }
}