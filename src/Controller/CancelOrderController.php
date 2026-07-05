<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use App\Entity\Order;
use App\Service\OrderService;
final class CancelOrderController extends AbstractController
{
    public function __invoke(
        Order $order,
        OrderService $orderService
    ): JsonResponse {
        try {
            $order = $orderService->cancelOrder($order);
            if ($order->getStatus() !== Order::STATUS_CANCELLED) {
                return $this->json(['status' => 'error', 'message' => 'Failed to cancel order']);
            }
            return $this->json(['status' => 'success', 'message' => 'Order cancelled successfully']);
        } catch (\Exception $e) {
            return $this->json(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }
}