<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AssignDelivery;
use App\Entity\Basket;
use App\Entity\Courrier;
use App\Entity\Order;
use App\Entity\OrderItem;
use App\Entity\OrderProcessed;
use App\Entity\Payment;
use App\Entity\Product;
use App\Entity\User;
use App\Enumeration\OrderStatusEnum;
use App\Message\OrderConfirmationMessage;
use App\Repository\OrderRepository;
use App\Repository\UserRepository;
use App\Service\GcsUploaderService;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Workflow\Registry as WorkflowRegistry;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

final class OrderService
{

    public function __construct(
        private EntityManagerInterface $entityManager,
        private TokenStorageInterface $tokenService,
        private UniqUidGenerator $uniqUidGenerator,
        private PromoCodeService $promoCodeService,
        private MessageBusInterface $messageBusInterface,
        private CardValidator $cardValidator,
        private OrderRepository $orderRepository,
        private UserRepository $userRepository,
        private int $expressShippingCost,
        private string $expressShippingDuration,
        private CurrencyProvider $currencyProvider,
        private GcsUploaderService $gcsUploaderService,
        private WorkflowRegistry $workflowRegistry,
        private CacheInterface $cache
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
        if (OrderStatusEnum::CANCELLED->value === $order->getStatus()) {
            throw new Exception('order_already_cancelled');
        }
        $user = $this->tokenService->getToken()?->getUser();
        if (!$user instanceof User || $order->getCustomer() !== $user) {
            throw new Exception('not_authorized_to_cancel_order');
        }

        $workflow = $this->workflowRegistry->get($order, 'order');
        if (!$workflow->can($order, 'cancel_order')) {
            throw new Exception('order_cannot_be_cancelled');
        }
        $workflow->apply($order, 'cancel_order');

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
        $taxAmount = ceil($totalAmount * $this->currencyProvider->getTaxRate());
        $promoCodeApplicable = $promoCode && $this->promoCodeService->checkPromoCode($promoCode, $basket);
        if ($promoCodeApplicable) {
            $totalAmount = $this->promoCodeService->amountAfterDiscount($promoCode, $basket);
            $discountAmount = $basketTotal - $totalAmount;
        }

        $isPaymentValid = in_array($paymentMethod, ['credit_card', 'wave-money', 'cod'], true);

        if ($isPaymentValid && 'credit_card' === $paymentMethod) {
            $isPaymentValid = [] === $this->cardValidator->validateCard($paymentDetails);
        }

        if (!$isPaymentValid) {
            throw new Exception('Invalid payment method or payment failed.');
        }

        $transactionId = 'txn_' . bin2hex(random_bytes(16));

        // Everything below is wrapped in one transaction so that a failure
        // partway through (e.g. while persisting order items) rolls back the
        // promo code usage too, instead of leaving it permanently consumed
        // with no order to show for it.
        [$order, $paymentTransaction, $orderItems, $shippingCost] = $this->entityManager->wrapInTransaction(
            function () use ($promoCodeApplicable, $promoCode, $basket, $shippingAddress, $totalAmount, $discountAmount, $taxAmount, $paymentMethod, $transactionId, $user, ) {
                // Only consume the promo code once payment has been validated, so a
                // rejected/invalid payment never burns a single-use code.
                if ($promoCodeApplicable) {
                    $this->promoCodeService->markAsUsed($promoCode, $basket);
                }

                return $this->persistOrder(
                    $shippingAddress,
                    $basket,
                    $totalAmount,
                    $discountAmount,
                    $taxAmount,
                    $paymentMethod,
                    $transactionId,
                    $user,
                );
            }
        );

        $this->sendOrderConfirmationMessage(
            $order,
            $paymentTransaction,
            $orderItems,
            $discountAmount,
            $order->getOrderPrice(),
            $basketTotal,
            $taxAmount,
            $shippingCost,
            $shippingAddress
        );

        return $order;
    }

    /**
     * @return array{0: Order, 1: Payment, 2: array, 3: float|int}
     */
    private function persistOrder(
        array $shippingAddress,
        Basket $basket,
        float $totalAmount,
        float $discountAmount,
        float $taxAmount,
        string $paymentMethod,
        string $transactionId,
        ?\Symfony\Component\Security\Core\User\UserInterface $user,
    ): array {
        // Creating a new order entity and setting its properties
        $order = new Order();
        $order->setStreet($shippingAddress['street'] ?? '');
        $order->setCity($shippingAddress['city'] ?? '');
        $order->setCountry('Ivory Coast');
        $order->setAccessToken(bin2hex(random_bytes(32)));
        $order->setSuite($shippingAddress['suite'] ?? '');
        $order->setState($shippingAddress['state'] ?? '');
        $order->setFullName($shippingAddress['fullName'] ?? '');
        $order->setEmail($shippingAddress['email'] ?? '');
        $order->setPhone($shippingAddress['phone'] ?? '');
        $isExpressShipping = $shippingAddress['shippingOption'] == 'express';
        if ($isExpressShipping) {
            $totalAmount += $this->expressShippingCost;
            $shippingCost = $this->expressShippingCost;
            $order->setEstimatedDeliveryAt((new \DateTime())->modify('+' . $this->expressShippingDuration));
            $order->setShippingFee($shippingCost);
        } else {
            $shippingCost = 0;
            $order->setShippingFee($shippingCost);
            $order->setEstimatedDeliveryAt((new \DateTime())->modify('+3 weeks'));
        }
        $order->setUid($this->uniqUidGenerator->generateUniqueUid(Order::class));
        $order->setIsPriority($isExpressShipping);
        $order->setDiscount(round($discountAmount, 1));
        $order->setTaxes($taxAmount);
        $order->setOrderPrice($totalAmount);
        $order->setStatus(Order::STATUS_ORDER_PLACED);

        if ($user instanceof User) {
            $order->setCustomer($user);
        } else {
            $userFromEmail = $shippingAddress['email'] ?? null;
            $foundUser = $this->userRepository->findOneBy(['email' => $userFromEmail]);
            if ($foundUser) {
                $order->setCustomer($foundUser);
            }
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
            $orderItem->setUnitPrice($item->getUnitPrice());
            $orderItem->setVariant($item->getVariant());
            $orderItem->setStatus(OrderItem::STATUS_PENDING);
            $this->entityManager->persist($orderItem);
            $orderItems[] = [
                'IMAGE_URL' => $this->resolveItemImageUrl($product, $item->getVariant()),
                'NAME' => $product->getTitle(),
                'VARIANT' => $item->getVariantLabel(),
                'QUANTITY' => $item->getQuantity(),
                'PRICE' => $item->getUnitPrice(),
                'SKU' => 'PR-' . $product->getId(),
            ];
        }

        //Saving payment transaction
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

        return [$order, $paymentTransaction, $orderItems, $shippingCost];
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
                $order->getAccessToken(),
                $payment->getPaymentLast4()
            )
        );
    }

    /**
     * Image shown for an order line in the confirmation email: the selected variant's own
     * image when it has one, falling back to the product cover image otherwise.
     *
     * @param array<string, mixed>|null $variant
     */
    private function resolveItemImageUrl(?Product $product, ?array $variant): ?string
    {
        $storedVariant = $product?->findVariant($variant);
        $image = $storedVariant['image'] ?? $variant['image'] ?? null;

        return is_string($image) && '' !== trim($image) ? $image : $product?->getCoverImage();
    }

    public function resendOrderConfirmation(Order $order): void
    {
        $payment = $order->getPayments()[0] ?? null;
        if (null === $payment) {
            throw new \InvalidArgumentException('No payment found for this order');
        }

        $items = [];
        $subTotal = 0;
        foreach ($order->getProducts() as $item) {
            $product = $item->getProduct();
            $items[] = [
                'IMAGE_URL' => $this->resolveItemImageUrl($product, $item->getVariant()),
                'NAME' => $product?->getTitle(),
                'VARIANT' => $item->getVariantLabel(),
                'QUANTITY' => $item->getQuantity(),
                'PRICE' => $item->getUnitPrice(),
                'SKU' => 'PR-' . $product?->getId(),
            ];
            $subTotal += $item->getUnitPrice() * $item->getQuantity();
        }

        // The order's own email column is only populated from the checkout shipping
        // form; orders placed by a logged-in customer may have it blank, so fall back
        // to the customer account email before giving up.
        $recipientEmail = $order->getEmail();
        if (null === $recipientEmail || '' === $recipientEmail) {
            $recipientEmail = $order->getCustomer()?->getEmail();
        }
        if (null === $recipientEmail || false === filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('No valid recipient email for this order');
        }

        $shippingAddress = [
            'email' => $recipientEmail,
            'fullName' => $order->getFullName(),
            'street' => $order->getStreet(),
            'suite' => $order->getSuite(),
            'city' => $order->getCity(),
            'state' => $order->getState(),
            'phone' => $order->getPhone(),
        ];

        $this->sendOrderConfirmationMessage(
            $order,
            $payment,
            $items,
            $order->getDiscount() ?? 0,
            $order->getOrderPrice(),
            $subTotal,
            $order->getTaxes() ?? 0,
            $order->getShippingFee() ?? 0,
            $shippingAddress
        );
    }

    public function assignPreviousOrderToUser(User $user): void
    {
        $email = $user->getEmail();
        $previousOrders = $this->orderRepository->findBy(['email' => $email]);
        foreach ($previousOrders as $previousOrder) {
            $previousOrder->setCustomer($user);
            $this->entityManager->persist($previousOrder);
        }
        $this->entityManager->flush();
    }

    public function editOrderDetails(Order $order, array $data): Order
    {
        if (isset($data['street']) && isset($data['city']) && isset($data['state'])) {
            $order->setStreet($data['street']);
            $order->setCity($data['city']);
            $order->setState($data['state']);
            if (isset($data['suite'])) {
                $order->setSuite($data['suite']);
            }
            $order->setFullName($data['fullName'] ?? $order->getFullName());
            $order->setPhone($data['phone'] ?? $order->getPhone());
            $this->entityManager->persist($order);
            $this->entityManager->flush();

            return $order;
        } else {
            throw new \InvalidArgumentException('Missing required address fields');
        }
    }

    public function addNoteToOrder(Order $order, string $note): void
    {
        if ('' === trim($note)) {
            throw new \InvalidArgumentException('note_cannot_be_empty');
        }
        $order->setFulfillmentNote($note);
        $this->entityManager->persist($order);
        $this->entityManager->flush();
    }

    public function markAsProcessed(Order $order, array $data, array $files): OrderProcessed
    {
        $workflow = $this->workflowRegistry->get($order, 'order');
        if ($workflow->can($order, 'processed_order')) {
            $orderProcessed = new OrderProcessed();
            $orderProcessed->setCurrentOrder($order);
            if (isset($data['seller'])) {
                $orderProcessed->setSeller($data['seller']);
            }
            if (isset($data['totalUsdAmount'])) {
                $orderProcessed->setTotalUsdAmount(
                    floatval($data['totalUsdAmount'])
                );
            }
            if (isset($data['sellerOrderReference'])) {
                $orderProcessed->setSellerOrderReference($data['sellerOrderReference']);
            }
            if (isset($data['processingNote'])) {
                $orderProcessed->setProcessingNote($data['processingNote']);
            }
            $orderProcessed->setProcessedAt(new \DateTime());

            if (isset($files['receipt'])) {
                $url = $this->gcsUploaderService->uploadOrderProcessingImage($files['receipt'], $order->getUid());
                $orderProcessed->setReceipt($url);
            } else {
                throw new \InvalidArgumentException('receipt_cannot_be_empty');
            }

            $workflow->apply($order, 'processed_order');
            $order->setUpdatedAt(new \DateTime());

            $this->entityManager->persist($orderProcessed);
            $this->entityManager->flush();

            return $orderProcessed;
        } else {
            throw new \LogicException('order_cannot_be_processed');
        }
    }

    public function markAsInTransit(Order $order, array $data, array $files): OrderProcessed
    {
        $workflow = $this->workflowRegistry->get($order, 'order');
        if ($workflow->can($order, 'ship_order')) {

            $orderProcessed = $order->getOrderProcesseds()->last();
            $orderProcessed->setShippedFromUsaAt(new \DateTime());

            $courrierRepository = $this->entityManager->getRepository(Courrier::class);

            if (!isset($data['carrier'], $data['shippingCost'])) {
                throw new \InvalidArgumentException('carrier_and_shippingCost_are_required');
            }
            $courrier = $courrierRepository->find($data['carrier']);
            if (!$courrier) {
                throw new \InvalidArgumentException('invalid_carrier');
            }
            $orderProcessed->setCourrier($courrier);
            $orderProcessed->setShippingCost(floatval($data['shippingCost']));
            if (isset($files['shippingReceipt'])) {
                $url = $this->gcsUploaderService->uploadOrderProcessingImage($files['shippingReceipt'], $order->getUid());
                $orderProcessed->setShippingReceipt($url);
            }
            if (isset($data['shippingNote'])) {
                $orderProcessed->setShippingNote($data['shippingNote']);
            }

            $workflow->apply($order, 'ship_order');
            $order->setUpdatedAt(new \DateTime());

            $this->entityManager->persist($orderProcessed);
            $this->entityManager->flush();

            return $orderProcessed;
        } else {
            throw new \LogicException('order_cannot_be_marked_in_transit');
        }
    }

    public function markAsArrivedInFacility(Order $order, array $data): OrderProcessed
    {
        $workflow = $this->workflowRegistry->get($order, 'order');
        if ($workflow->can($order, 'arrived_in_facility')) {
            $orderProcessed = $order->getOrderProcesseds()->last();
            if (!isset($data['lastFour'])) {
                throw new \InvalidArgumentException('lastFour_is_required');
            }
            $sellerOrderReference = $orderProcessed->getSellerOrderReference();
            $lastFour = $data['lastFour'];
            if (substr($sellerOrderReference, -4) !== $lastFour) {
                throw new \InvalidArgumentException('lastFour_does_not_match_seller_order_reference');
            }

            $workflow->apply($order, 'arrived_in_facility');
            $order->setUpdatedAt(new \DateTime());
            $orderProcessed->setCollectedAt(new \DateTime());
            if (isset($data['collectionNote'])) {
                $orderProcessed->setCollectionNote($data['collectionNote']);
            }
            $this->entityManager->persist($orderProcessed);
            $this->entityManager->flush();

            return $orderProcessed;
        } else {
            throw new \LogicException('order_cannot_be_marked_arrived_in_facility');
        }
    }

    public function markAsOutForDelivery(Order $order): OrderProcessed
    {
        $workflow = $this->workflowRegistry->get($order, 'order');
        if ($workflow->can($order, 'out_for_delivery')) {
            $orderProcessed = $order->getOrderProcesseds()->last();

            $workflow->apply($order, 'out_for_delivery');
            $order->setUpdatedAt(new \DateTime());
            $orderProcessed->setOutForDeliveryAt(new \DateTime());

            $this->entityManager->persist($orderProcessed);
            $this->entityManager->flush();

            return $orderProcessed;
        } else {
            throw new \LogicException('order_cannot_be_marked_out_for_delivery');
        }
    }

    public function getAdminSummary(): array
    {
        return $this->cache->get('admin_summary', function (ItemInterface $item) {
            $item->expiresAfter(5 * 3600);

            return $this->computeAdminSummary();
        });
    }

    private function computeAdminSummary(): array
    {
        $yesterday = new \DateTime('yesterday');
        // cache the yesterday orders count for 5 hours
        $yesterdayOrdersCount = $this->cache->get('yesterday_orders_count', function (ItemInterface $item) use ($yesterday) {
            $item->expiresAfter(5 * 3600);

            return $this->orderRepository->createQueryBuilder('o')
                ->select('COUNT(o.id)')
                ->where('o.createdAt >= :yesterday')
                ->andWhere('o.createdAt < :today')
                ->setParameter('yesterday', $yesterday->format('Y-m-d 00:00:00'))
                ->setParameter('today', (new \DateTime())->format('Y-m-d 00:00:00'))
                ->getQuery()
                ->getSingleScalarResult();
        });

        // day before yesterday orders count for 5 hours
        $dayBeforeYesterday = new \DateTime('2 days ago');
        $dayBeforeYesterdayOrdersCount = $this->cache->get('day_before_yesterday_orders_count', function (ItemInterface $item) use ($dayBeforeYesterday) {
            $item->expiresAfter(5 * 3600);

            return $this->orderRepository->createQueryBuilder('o')
                ->select('COUNT(o.id)')
                ->where('o.createdAt >= :dayBeforeYesterday')
                ->andWhere('o.createdAt < :yesterday')
                ->setParameter('dayBeforeYesterday', $dayBeforeYesterday->format('Y-m-d 00:00:00'))
                ->setParameter('yesterday', (new \DateTime('yesterday'))->format('Y-m-d 00:00:00'))
                ->getQuery()
                ->getSingleScalarResult();
        });

        // cache the priority orders awaiting count for 5 min
        $priorityOrdersAwaitingCount = $this->cache->get('priority_orders_awaiting_count', function (ItemInterface $item) {
            $item->expiresAfter(5 * 60);

            return $this->orderRepository->createQueryBuilder('o')
                ->select('COUNT(o.id)')
                ->andWhere('o.isPriority = :priority')
                ->andWhere('o.status = :status')
                ->setParameter('status', 'placed')
                ->setParameter('priority', 1)
                ->getQuery()
                ->getSingleScalarResult();
        });

        // cache the orders in transit count for 5 min
        $ordersInTransitCount = $this->cache->get('orders_in_transit_count', function (ItemInterface $item) {
            $item->expiresAfter(5 * 60);

            return $this->orderRepository->createQueryBuilder('o')
                ->select('COUNT(o.id)')
                ->andWhere('o.status = :status')
                ->setParameter('status', 'in_transit')
                ->getQuery()
                ->getSingleScalarResult();
        });

        // cache the new placed orders count for 5 min
        $newPlacedOrdersCount = $this->cache->get('new_placed_orders_count', function (ItemInterface $item) {
            $item->expiresAfter(5 * 60);

            return $this->orderRepository->createQueryBuilder('o')
                ->select('COUNT(o.id)')
                ->andWhere('o.status = :status')
                ->setParameter('status', 'placed')
                ->getQuery()
                ->getSingleScalarResult();
        });

        // cache the orders awaiting delivery count for 5 min
        $ordersAwaitingDeliveryCount = $this->cache->get('orders_awaiting_delivery_count', function (ItemInterface $item) {
            $item->expiresAfter(5 * 60);

            return $this->orderRepository->createQueryBuilder('o')
                ->select('COUNT(o.id)')
                ->andWhere('o.status = :status')
                ->setParameter('status', 'out_for_delivery')
                ->getQuery()
                ->getSingleScalarResult();
        });

        // cache the lifetime orders count for 2 hours
        $lifetimeOrders = $this->cache->get('lifetime_orders', function (ItemInterface $item) {
            $item->expiresAfter(2 * 3600);
            return $this->orderRepository->count();
        });

        $lifetimeDeliveredOrders = $this->cache->get('lifetime_delivered_orders', function (ItemInterface $item) {
            $item->expiresAfter(5 * 3600);
            return $this->orderRepository->createQueryBuilder('o')
                ->select('COUNT(o.id)')
                ->andWhere('o.status = :status')
                ->setParameter('status', 'delivered')
                ->getQuery()
                ->getSingleScalarResult();
        });

        $yesterdayDeliveredOrders = $this->cache->get('yesterday_delivered_orders', function (ItemInterface $item) {
            $item->expiresAfter(5 * 3600);
            $yesterday = new \DateTime('yesterday');
            return $this->orderRepository->createQueryBuilder('o')
                ->select('COUNT(o.id)')
                ->andWhere('o.status = :status')
                ->andWhere('o.createdAt >= :yesterday')
                ->andWhere('o.createdAt < :today')
                ->setParameter('status', 'delivered')
                ->setParameter('yesterday', $yesterday->format('Y-m-d 00:00:00'))
                ->setParameter('today', (new \DateTime('today'))->format('Y-m-d 00:00:00'))
                ->getQuery()
                ->getSingleScalarResult();
        });

        return ['yesterdayOrders' => $yesterdayOrdersCount, 'priorityOrdersAwaiting' => $priorityOrdersAwaitingCount, 'ordersInTransit' => $ordersInTransitCount, 'ordersAwaitingDelivery' => $ordersAwaitingDeliveryCount, 'lifetimeOrders' => $lifetimeOrders, 'dayBeforeYesterdayOrders' => $dayBeforeYesterdayOrdersCount, 'lifetimeDeliveredOrders' => $lifetimeDeliveredOrders, 'yesterdayDeliveredOrders' => $yesterdayDeliveredOrders, 'newPlacedOrders' => $newPlacedOrdersCount];
    }

    public function batchAssignDelivery(array $orders, User $deliveryPerson): int
    {
        $processed = 0;
        foreach ($orders as $orderData) {
            $order = $this->orderRepository->findOneBy(['uid' => $orderData['uid']]);
            if ($order && $order->getStatus() === OrderStatusEnum::OUT_FOR_DELIVERY->value) {
                $orderAssign = new AssignDelivery();
                $orderAssign->setAssignOrder($order);
                $orderAssign->setDriver($deliveryPerson);

                $this->entityManager->persist($orderAssign);
                $this->entityManager->persist($order);
                $processed++;
            }
        }
        $this->entityManager->flush();
        return $processed;
    }
}