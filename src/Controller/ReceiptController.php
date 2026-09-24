<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use App\Repository\OrderRepository;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use App\Service\ReceiptService;

final class ReceiptController extends AbstractController
{
    #[Route('/api/orders/{id}/receipt', name: 'app_receipt', methods: ['GET'])]
    public function index(
        string $id,
        Request $request,
        OrderRepository $orderRepository,
        ReceiptService $receiptService,
        TokenStorageInterface $tokenStorage,
    ): JsonResponse {
        $order = $orderRepository->findOneBy(['uid' => $id]);
        $accessToken = $request->query->get('accessToken');

        $token = $tokenStorage->getToken();

        $hasAccess = false;

        if (null === $order) {
            return new JsonResponse(['message' => 'Order not found'], 404);
        }

        if (null === $accessToken) {
            $hasAccess = false;
        }

        if ($accessToken === $order->getAccessToken()) {
            $hasAccess = true;
        }

        if ($accessToken === 'self') {
            $user = $token->getUser();
            $hasAccess = $order->getCustomer() === $user;
        } else if ($accessToken === 'admin') {
            $user = $token->getUser();
            $hasAccess = $user && in_array('ROLE_ADMIN', $user->getRoles());
        } else {
            $hasAccess = false;
        }

        if (!$hasAccess) {
            return new JsonResponse(['message' => 'Access denied'], 403);
        }

        $items = [];
        $subtotal = 0;

        $taxesRate = $this->getParameter('taxe_rate');
        $currency = $this->getParameter('currency');

        $allItems = $order->getProducts();
        foreach ($allItems as $item) {
            $items[] = [
                'productName' => $item->getProduct()->getTitle(),
                'qty' => $item->getQuantity(),
                'unit_price' => $item->getUnitPrice(),
                'sku' => 'PR-' . $item->getProduct()->getId(),
                'variant' => $item->getVariant(),
            ];
            $subtotal += $item->getUnitPrice() * $item->getQuantity();
        }

        $payments = $order->getPayments()[0];

        if (null === $payments) {
            return new JsonResponse(['message' => 'error: payment not found'], 404);
        }

        $paymentData = [
            'transactionId' => $payments->getTransactionId(),
            'method' => $payments->getPaymentMethod(),
            'amount' => $payments->getAmount(),
            'status' => $payments->getStatus(),
            'last4' => $payments->getPaymentLast4() ?? null,
        ];

        $orderData = [
            'reference' => $order->getUid(),
            'createdAt' => $order->getCreatedAt(),
            'customer' => [
                'fullName' => $order->getFullName(),
                'phone' => $order->getPhone(),
                'email' => $order->getEmail(),
                'address' => $order->getFullAddress(),
            ],
            'subtotal' => $subtotal,
            'discount' => $order->getDiscount(),
            'shipping_tier' => $order->isPriority() ? 'Express' : 'Standard',
            'shipping' => $order->getShippingFee(),
            'taxRate' => (float) $taxesRate * 100,
            'tax' => $order->getTaxes(),
            'total' => $order->getOrderPrice(),
            'items' => $items,
            'currency' => $currency,
            'payment' => $paymentData,
        ];

        return new JsonResponse([
            'order' => $orderData,
            'barcode' => $receiptService->generateBarcode($order->getUid()),
        ], 200);
    }
}