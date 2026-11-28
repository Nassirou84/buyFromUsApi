<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\OrderRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
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
            return new JsonResponse(['error' => 'order_not_found'], Response::HTTP_NOT_FOUND);
        }

        $items = [];
        foreach ($order->getProducts() as $orderItem) {
            $items[] = [
                'id' => $orderItem->getId(),
                'title' => $orderItem->getProduct()?->getTitle(),
                'quantity' => $orderItem->getQuantity(),
            ];
        }

        $orderAcceptStoreCredit = false;
        if ($order->getCustomer()) {
            $orderAcceptStoreCredit = true;
        }

        return new JsonResponse([
            'items' => $items,
            'acceptStoreCredit' => $orderAcceptStoreCredit,
        ], Response::HTTP_OK);
    }
}