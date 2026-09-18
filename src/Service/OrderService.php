<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Basket;
use App\Entity\Order;
use App\Entity\OrderItem;
use App\Entity\Payment;
use App\Message\OrderConfirmationMessage;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

final class OrderService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private TokenStorageInterface $tokenService,
        private UniqUidGenerator $uniqUidGenerator,
        private PromoCodeService $promoCodeService,
        private MessageBusInterface $messageBusInterface,
        private int $expressShippingCost,
        private string $expressShippingDuration,
    ) {
    }

    public function createOrder(Order $order): Order
    {
        if (!$this->tokenService->getToken()) {
            throw new Exception('Utilisateur non authentifié.');
        }
        $currentUser = $this->tokenService->getToken()->getUser();
        if (!$currentUser instanceof \App\Entity\User) {
            throw new Exception('Utilisateur non authentifié.');
        }
        $order->setCustomer($currentUser);
        $order->setUid($this->uniqUidGenerator->generateUniqueUid(Order::class));

        $this->entityManager->persist($order);
        $this->entityManager->flush();

        return $order;
    }

    public function cancelOrder(Order $order): Order
    {
        if (Order::STATUS_CANCELLED === $order->getStatus()) {
            throw new Exception('order_already_cancelled');
        }
        $user = $this->tokenService->getToken()->getUser();
        if ($order->getCustomer() !== $user) {
            throw new Exception('not_authorized_to_cancel_order');
        }
        $order->setStatus(Order::STATUS_CANCELLED);
        $this->entityManager->persist($order);
        $this->entityManager->flush();

        return $order;
    }

    public function placeNewOrder(
        array $shippingAddress,
        Basket $basket,
        string $paymentMethod,
        array $paymentDetails,
        ?string $promoCode = null,
    ): Order {
        $user = null;
        $token = $this->tokenService->getToken();
        if ($token) {
            $user = $token->getUser();
        }

        $basketTotal = $basket->getTotalAmount();
        $totalAmount = $basketTotal;
        $discountAmount = 0;
        if ($promoCode && $this->promoCodeService->checkPromoCode($promoCode, $basket)) {
            $totalAmount = $this->promoCodeService->amountAfterDiscount($promoCode, $basket);
            $discountAmount = $basketTotal - $totalAmount;

            $this->promoCodeService->markAsUsed($promoCode, $basket);
        }

        $isPaymentValid = false;
        $transactionId = uniqid('txn_');

        if ($paymentMethod === 'credit_card') {
            // Handle credit card payment logic here
            $isPaymentValid = true;
            $transactionId = uniqid('txn_');
        }

        if ($paymentMethod === 'wave-money') {
            // Handle wave payment logic here
            $isPaymentValid = true;
            $transactionId = uniqid('txn_');
        }

        if ($paymentMethod === 'cod') {
            // Handle cash on delivery logic here
            $isPaymentValid = true;
            $transactionId = uniqid('txn_');
        }

        if (!$isPaymentValid) {
            throw new Exception('Invalid payment method or payment failed.');
        }

        // Creating a new order entity and setting its properties
        $order = new Order();
        $order->setStreet($shippingAddress['street'] ?? '');
        $order->setCity($shippingAddress['city'] ?? '');
        $order->setCountry('Ivory Coast');
        $order->setSuite($shippingAddress['suite'] ?? '');
        $order->setState($shippingAddress['state'] ?? '');
        $order->setFullName($shippingAddress['fullName'] ?? '');
        $order->setEmail($shippingAddress['email'] ?? '');
        $isExpressShipping = $shippingAddress['shippingOption'] == 'express';
        if ($isExpressShipping) {
            $totalAmount += $this->expressShippingCost;
            $shippingCost = $this->expressShippingCost;
            $order->setEstimatedDeliveryAt((new \DateTime())->modify('+' . $this->expressShippingDuration));
        } else {
            $shippingCost = 0;
            $order->setEstimatedDeliveryAt((new \DateTime())->modify('+3 weeks'));
        }
        $order->setUid($this->uniqUidGenerator->generateUniqueUid(Order::class));
        $order->setIsPriority($isExpressShipping);
        $order->setOrderPrice($totalAmount);
        $order->setStatus(Order::STATUS_ORDER_PLACED);

        if ($user instanceof \App\Entity\User) {
            $order->setCustomer($user);
        }
        $this->entityManager->persist($order);

        //Saving order items
        $items = $basket->getBasketItems();
        $orderItems = [];
        foreach ($items as $item) {
            $product = $item->getProduct();

            $orderItem = new OrderItem();
            $orderItem->setCurrentOrder($order);
            $orderItem->setProduct($product);
            $orderItem->setQuantity($item->getQuantity());
            $orderItem->setUnitPrice($product->getActualPrice());
            $orderItem->setVariant($item->getVariant());
            $orderItem->setStatus(OrderItem::STATUS_PENDING);
            $this->entityManager->persist($orderItem);
            $orderItems[] = [
                'NAME' => $product->getTitle(),
                'VARIANT' => $item->getVariant(),
                'QUANTITY' => $item->getQuantity(),
                'PRICE' => $product->getActualPrice(),
                'SKU' => 'PR-' . $product->getId(),
            ];
        }

        //Saving payment transaction
        $taxAmount = ceil($totalAmount * 0.18);
        $paymentTransaction = new Payment();
        $paymentTransaction->setUserOrder($order);
        $paymentTransaction->setPaymentMethod($paymentMethod);
        $paymentTransaction->setTransactionId($transactionId);
        $paymentTransaction->setStatus(Payment::STATUS_COMPLETED);
        $paymentTransaction->setCurrencyCode('XOF');
        $paymentTransaction->setAmount($totalAmount);
        $paymentTransaction->setTaxedAmount($taxAmount);
        if ($paymentMethod === 'cod') {
            $paymentTransaction->setStatus(Payment::STATUS_PENDING);
        }
        if ($paymentMethod === 'credit_card') {
            $paymentTransaction->setStatus(Payment::STATUS_COMPLETED);
            $paymentTransaction->setPaymentCard('...4641');
        }
        if ($paymentMethod === 'wave-money') {
            $paymentTransaction->setStatus(Payment::STATUS_COMPLETED);
            $paymentTransaction->setMobileMoney('...4641');
        }
        $this->entityManager->persist($paymentTransaction);
        $this->entityManager->flush();

        $this->sendOrderConfirmationMessage(
            $order,
            $paymentTransaction,
            $orderItems,
            $discountAmount,
            $totalAmount,
            $basketTotal,
            $taxAmount,
            $shippingCost,
            $shippingAddress
        );

        return $order;
    }

    public function sendOrderConfirmationMessage(Order $order, Payment $payment, $items, $discountAmount, $totalAmount, $subTotal, $taxAmount, $shippingCost, $shippingAddress, ): void
    {
        $paymentType = match ($payment->getPaymentMethod()) {
            'cod' => 'Cash à la livraison',
            'credit_card' => 'Carte de Crédit',
            'wave-money' => 'Wave',
            default => 'Unknown',
        };

        $this->messageBusInterface->dispatch(
            new OrderConfirmationMessage(
                $order->getUid(),
                $shippingAddress['email'],
                $order->getFullName(),
                $payment->getTransactionId(),
                $order->getEstimatedDeliveryAt()->format('d-m-Y'),
                $order->isPriority() ? 'Express' : 'Standard',
                $totalAmount,
                $taxAmount,
                $subTotal,
                $shippingCost,
                $discountAmount,
                $payment->getStatus(),
                $items,
                $shippingAddress,
                $paymentType,
                $payment->getPaymentLast4()
            )
        );
    }
}