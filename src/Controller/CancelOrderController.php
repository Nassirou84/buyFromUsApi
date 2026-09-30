<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\OrderService;
use App\Repository\OrderRepository;
use Exception;
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
        if (null === $order) {
            return $this->json(['success' => false, 'message' => 'order_not_found'], 404);
        }

        try {
            $orderService->cancelOrder($order);
        } catch (Exception $e) {
            return $this->json(['success' => false, 'message' => $e->getMessage()], 403);
        }

        return $this->json(['success' => true, 'message' => 'order_cancelled_successfully']);
    }
}