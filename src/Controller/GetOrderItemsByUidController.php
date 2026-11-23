<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\OrderRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class GetOrderItemsByUidController extends AbstractController
{
    #[Route('/api/orders/uid/{uid}/items', name: 'app_order_items_by_uid', methods: ['GET'])]
    public function __invoke(
        string $uid,
        OrderRepository $orderRepository,
    ): JsonResponse {
        $order = $orderRepository->findOneBy(['uid' => $uid]);

        if (!$order) {
            return new JsonResponse(['error' => 'order_not_found'], JsonResponse::HTTP_NOT_FOUND);
        }

        $items = [];
        foreach ($order->getProducts() as $orderItem) {
            $items[] = [
                'id' => $orderItem->getId(),
                'title' => $orderItem->getProduct()?->getTitle(),
                'quantity' => $orderItem->getQuantity(),
            ];
        }

        return new JsonResponse(['items' => $items], JsonResponse::HTTP_OK);
    }
}
