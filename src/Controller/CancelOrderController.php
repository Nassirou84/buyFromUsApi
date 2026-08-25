<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Order;
use App\Service\OrderService;
use Exception;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;

final class CancelOrderController extends AbstractController
{
    public function __invoke(
        Order $order,
        OrderService $orderService,
    ): JsonResponse {
        try {
            $order = $orderService->cancelOrder($order);
            if (Order::STATUS_CANCELLED !== $order->getStatus()) {
                return $this->json(['status' => 'error', 'message' => 'Failed to cancel order']);
            }

            return $this->json(['status' => 'success', 'message' => 'Your order has been cancelled successfully']);
        } catch (Exception $e) {
            return $this->json(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }
}