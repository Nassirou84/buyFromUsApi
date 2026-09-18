<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\OrderService;
use App\Repository\OrderRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;

final class CancelOrderController extends AbstractController
{
    public function __invoke(
        string $id,
        OrderRepository $orderRepository,
        OrderService $orderService,
    ): JsonResponse {
        $order = $orderRepository->findOneBy(['uid' => $id]);
        $order = $orderService->cancelOrder($order);
        return $this->json(['success' => true, 'message' => 'order_cancelled_successfully']);
    }
}