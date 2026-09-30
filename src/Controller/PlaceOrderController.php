<?php

declare(strict_types=1);

namespace App\Controller;
use App\Repository\BasketRepository;
use App\Service\OrderService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

final class PlaceOrderController extends AbstractController
{
    public function __invoke(
        Request $request,
        OrderService $orderService,
        BasketRepository $basketRepository,
        TokenStorageInterface $tokenStorage,
    ): JsonResponse {
        $data = json_decode($request->getContent(), true);

        $shippingAddress = $data['shippingAddress'] ?? [];
        $basketUid = $data['basketUid'] ?? null;
        $paymentMethod = $data['paymentMethod'] ?? null;
        $paymentDetails = $data['paymentDetails'] ?? null;
        $promoCode = $data['promoCode'] ?? null;

        $basket = $basketRepository->findOneBy(['uid' => $basketUid]);

        if (null === $basket) {
            return new JsonResponse(['success' => false, 'message' => 'basket_not_found'], 404);
        }

        // A basket already tied to an account may only be checked out by that
        // same account, otherwise an attacker who guesses/leaks a basketUid
        // could complete someone else's basket using their own shipping/payment details.
        $currentUser = $tokenStorage->getToken()?->getUser();
        if ($basket->getUser() && $basket->getUser() !== $currentUser) {
            return new JsonResponse(['success' => false, 'message' => 'basket_not_found'], 404);
        }

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