<?php

declare(strict_types=1);

namespace App\Controller;
use App\Repository\BasketRepository;
use App\Service\OrderService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class PlaceOrderController extends AbstractController
{
    public function __invoke(
        Request $request,
        OrderService $orderService,
        BasketRepository $basketRepository,
    ): JsonResponse {
        $data = json_decode($request->getContent(), true);

        $shippingAddress = $data['shippingAddress'] ?? [];
        $basketUid = $data['basketUid'] ?? null;
        $paymentMethod = $data['paymentMethod'] ?? null;
        $paymentDetails = $data['paymentDetails'] ?? null;
        $promoCode = $data['promoCode'] ?? null;

        $basket = $basketRepository->findOneBy(['uid' => $basketUid]);

        $order = $orderService->placeNewOrder(
            $shippingAddress,
            $basket,
            $paymentMethod,
            $paymentDetails,
            $promoCode
        );

        $items = [];
        foreach ($basket->getBasketItems() as $item) {
            $items[] = [
                'productName' => $item->getProduct()->getTitle(),
                'quantity' => $item->getQuantity()
            ];
        }

        return new JsonResponse([
            'success' => true,
            'orderNumber' => $order->getUid(),
            'email' => $order->getEmail(),
            'estimatedDeliveryAt' => $order->getEstimatedDeliveryAt() ? $order->getEstimatedDeliveryAt()->format('Y-m-d H:i:s') : null,
            'totalAmount' => $order->getOrderPrice(),
            'fullAddress' => $order->getFullAddress() ?? null,
            'items' => $items,
        ]);
    }
}