<?php

namespace App\Service;
use App\Entity\Order;
use App\Entity\OrderItem;
use App\Entity\ProductReturn;
use App\Entity\ProductReturnItem;
use App\Entity\PromoCode;
use App\Entity\Refund;
use App\Entity\User;
use App\Enumeration\OrderStatusEnum;
use App\Enumeration\ProductReturnDecisionEnum;
use App\Enumeration\ProductReturnReasonEnum;
use App\Enumeration\ProductReturnStatusEnum;
use App\Repository\OrderItemRepository;
use App\Repository\OrderRepository;
use App\Repository\ProductReturnItemRepository;
use App\Service\StoreCreditService;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Workflow\Registry as WorkflowRegistry;


final class ProductReturnService
{
  public function __construct(
    private EntityManagerInterface $entityManagerInterface,
    private OrderRepository $orderRepository,
    private UniqUidGenerator $uniqUidGenerator,
    private ProductReturnItemRepository $productReturnItemRepository,
    private OrderItemRepository $orderItemRepository,
    private WorkflowRegistry $workflowRegistry,
    private PromoCodeService $promoCodeService,
    private StoreCreditService $storeCreditService,
    private InventoryService $inventoryService,
    private RefundService $refundService,
  ) {
  }

  public function initiateReturn(array $requestData): ProductReturn
  {
    $order = $this->orderRepository->findOneBy(['uid' => $requestData['orderNumber'] ?? null]);

    if (!$order) {
      throw new \InvalidArgumentException('order_not_found');
    }

    if ($order->getStatus() !== OrderStatusEnum::DELIVERED->value) {
      throw new \InvalidArgumentException('only_delivered_orders_allowed');
    }

    $deliveredAt = $order->getDeliveredAt();
    if (!$deliveredAt) {
      throw new \InvalidArgumentException('order_not_delivered');
    }
    // 10 days delivery return window
    $returnWindow = (clone $deliveredAt)->modify('+10 days');
    if (new \DateTime() > $returnWindow) {
      throw new \InvalidArgumentException('return_window_expired');
    }

    if (empty($requestData['email'])) {
      throw new \InvalidArgumentException('email_is_required');
    }

    if ($order->getEmail() !== $requestData['email']) {
      throw new \InvalidArgumentException('email_mismatch');
    }

    if (!in_array($requestData['resolution'] ?? '', [ProductReturn::STORE_CREDIT, ProductReturn::REFUND], true)) {
      throw new \InvalidArgumentException('invalid_resolution');
    }

    $lines = $requestData['items'] ?? null;
    if (!is_array($lines) || count($lines) === 0) {
      throw new \InvalidArgumentException('return_items_are_required');
    }

    if ($requestData['resolution'] === ProductReturn::STORE_CREDIT && !$order->getCustomer()) {
      throw new \InvalidArgumentException('store_credit_not_available_for_guest_order');
    }

    $return = new ProductReturn();
    $return->setReturnOrder($order);
    $return->setEmail($requestData['email']);
    $return->setRefundtype($requestData['resolution']);
    $return->setAdditionalDetails($requestData['message'] ?? null);
    $return->setUid($this->uniqUidGenerator->generateUniqueUid(ProductReturn::class));
    $return->setStatus(ProductReturnStatusEnum::INITIATED->value);

    // Requested quantity per order item within THIS request (guards duplicates).
    $requestedPerItem = [];
    foreach ($lines as $line) {
      $itemId = $line['itemId'] ?? null;
      if (!$itemId) {
        throw new \InvalidArgumentException('item_is_required');
      }

      $orderItem = $this->orderItemRepository->find($itemId);
      if (!$orderItem) {
        throw new \InvalidArgumentException('item_not_found');
      }

      if ($orderItem->getCurrentOrder()?->getUid() !== $order->getUid()) {
        throw new \InvalidArgumentException('item_does_not_belong_to_order');
      }

      if (isset($requestedPerItem[$itemId])) {
        throw new \InvalidArgumentException('duplicate_item_in_request');
      }

      $quantity = (int) ($line['quantity'] ?? 0);
      if ($quantity < 1) {
        throw new \InvalidArgumentException('invalid_return_quantity');
      }

      if (empty($line['reason'])) {
        throw new \InvalidArgumentException('return_reason_is_required');
      }

      $availableQuantity = ($orderItem->getQuantity() ?? 0) - $this->returnedQuantityForItem($orderItem);
      if ($quantity > $availableQuantity) {
        throw new \InvalidArgumentException('return_quantity_exceeds_available');
      }

      $requestedPerItem[$itemId] = $quantity;

      $returnItem = new ProductReturnItem();
      $returnItem->setOrderItem($orderItem);
      $returnItem->setQuantity($quantity);
      $returnItem->setReason($line['reason']);
      $return->addReturnItem($returnItem);
    }

    $this->entityManagerInterface->persist($return);
    $this->entityManagerInterface->flush();

    return $return;
  }

  /**
   * Total units of an order item already claimed by a return, whatever the return's status
   * (initiated, collected or completed), so a unit can never be returned and refunded twice.
   * Only lines an admin rejected free their quantity.
   */
  public function returnedQuantityForItem(OrderItem $orderItem): int
  {
    $total = 0;
    foreach ($this->productReturnItemRepository->findBy(['orderItem' => $orderItem]) as $returnItem) {
      if ($returnItem->getDecision() !== ProductReturnDecisionEnum::REJECTED->value) {
        $total += $returnItem->getQuantity() ?? 0;
      }
    }

    return $total;
  }

  /**
   * A return is "whole" when its lines cover every order item at full quantity.
   */
  public function isWholeReturn(ProductReturn $return): bool
  {
    $order = $return->getReturnOrder();
    if (!$order) {
      return false;
    }

    $returnedPerItem = [];
    foreach ($return->getReturnItems() as $returnItem) {
      $itemId = $returnItem->getOrderItem()?->getId();
      if ($itemId !== null) {
        $returnedPerItem[$itemId] = ($returnedPerItem[$itemId] ?? 0) + ($returnItem->getQuantity() ?? 0);
      }
    }

    foreach ($order->getProducts() as $orderItem) {
      $itemId = $orderItem->getId();
      if ($itemId === null || ($returnedPerItem[$itemId] ?? 0) < ($orderItem->getQuantity() ?? 0)) {
        return false;
      }
    }

    return count($returnedPerItem) > 0;
  }

  public function updateReturnItemStatus(ProductReturnItem $item, array $inpectionResult, User $inpectedBy): ProductReturnItem
  {
    $condition = $inpectionResult['condition'] ?? null;
    if ($condition !== null) {
      $item->setItemCondition($condition);
    }
    $controls = $inpectionResult['controls'] ?? null;
    if (count($controls) > 0) {
      $item->setItemControls($controls);
    }
    $item->setInspectedBy($inpectedBy);
    $item->setInspectedAt(new \DateTime());

    $this->entityManagerInterface->persist($item);
    $this->entityManagerInterface->flush();

    return $item;
  }

  public function addReturnNote(ProductReturn $return, string $note): ProductReturn
  {
    $return->setReturnNote($note);

    $this->entityManagerInterface->persist($return);
    $this->entityManagerInterface->flush();

    return $return;
  }

  public function approveReturnItem(ProductReturnItem $item, User $approvedBy): ProductReturnItem
  {
    $item->setDecision(ProductReturnDecisionEnum::APPROVED->value);
    $item->setDecidedBy($approvedBy);
    $item->setDecidedAt(new \DateTime());

    $this->entityManagerInterface->persist($item);
    $this->entityManagerInterface->flush();

    return $item;
  }

  public function rejectReturnItem(ProductReturnItem $item, User $rejectedBy): ProductReturnItem
  {
    $item->setDecision(ProductReturnDecisionEnum::REJECTED->value);
    $item->setDecidedBy($rejectedBy);
    $item->setDecidedAt(new \DateTime());

    $this->entityManagerInterface->persist($item);
    $this->entityManagerInterface->flush();

    return $item;
  }

  /**
   * Closes a collected return and gives the money back (store credit or gateway refund).
   *
   * For a gateway refund, the Refund row is written and the gateway called BEFORE the return
   * is marked completed: if the gateway refuses or its outcome is unknown, the exception
   * propagates and the return stays `collected` (the failed attempt stays on record).
   */
  public function completeReturn(ProductReturn $return, User $completedBy): ProductReturn
  {
    $productReturnWorkflow = $this->workflowRegistry->get($return, 'product_return');
    if (!$productReturnWorkflow->can($return, 'complete_return')) {
      throw new \LogicException('cannot_complete_return');
    }

    $order = $return->getReturnOrder();
    $breakdown = $this->getRefundBreakdown($return);
    $totalToBeRefunded = $breakdown['items'] + $breakdown['shipping'];

    if ($return->getRefundType() === ProductReturn::STORE_CREDIT && $totalToBeRefunded > 0 && !$order->getCustomer()) {
      throw new \LogicException('store_credit_requires_customer_account');
    }

    $existingRefund = null;
    foreach ($return->getRefunds() as $refund) {
      if ($refund->isActive()) {
        $existingRefund = $refund;
      }
    }
    if ($existingRefund && $existingRefund->getStatus() === Refund::STATUS_NEEDS_ATTENTION) {
      throw new \LogicException('refund_needs_reconciliation');
    }

    // An accepted refund already exists when a previous completion reached the gateway but
    // failed afterwards: finish closing the return without refunding twice.
    if ($return->getRefundType() === ProductReturn::REFUND && $totalToBeRefunded > 0 && !$existingRefund) {
      $payment = $this->refundService->findRefundablePayment($order);
      if (!$payment) {
        throw new \LogicException('no_refundable_payment_for_order');
      }

      // Tax is not added to what the customer pays (Paystack is charged Order::orderPrice =
      // items after discount + shipping), so there is no extra tax to give back.
      $this->refundService->refundProductReturn(
        $return,
        $payment,
        $completedBy,
        $breakdown['items'],
        $breakdown['shipping'],
        0,
      );
    }

    // Status, store credit and inventory are committed together or not at all.
    $this->entityManagerInterface->wrapInTransaction(function () use ($return, $productReturnWorkflow, $totalToBeRefunded, $completedBy, $order): void {
      // Lock the return row (SELECT ... FOR UPDATE) and reload its status, then re-check:
      // a concurrent completion (double click, retry) waits here until the first one commits,
      // then sees `completed` and stops, so store credit and inventory are never applied twice.
      $this->entityManagerInterface->refresh($return, LockMode::PESSIMISTIC_WRITE);
      if (!$productReturnWorkflow->can($return, 'complete_return')) {
        throw new \LogicException('cannot_complete_return');
      }

      $productReturnWorkflow->apply($return, 'complete_return');
      $return->setTotalToBeRefunded($totalToBeRefunded);
      $return->setCompletedAt(new \DateTime());
      $return->setCompletedBy($completedBy);

      if ($return->getRefundType() === ProductReturn::STORE_CREDIT && $totalToBeRefunded > 0) {
        $this->storeCreditService->addStoreCredit($order->getCustomer(), $totalToBeRefunded, $completedBy, $return);
      }

      // Update inventory for returned items.
      foreach ($return->getReturnItems() as $item) {
        if ($item->getDecision() === ProductReturnDecisionEnum::APPROVED->value) {
          $this->inventoryService->updateInventoryForReturnItem($item);
        }
      }
      $this->entityManagerInterface->persist($return);
    });

    return $return;
  }

  /**
   * Amount owed to the customer for the approved lines of a return.
   *
   * - Items: unit price x approved quantity, minus the promo code discount on that line
   *   (see lineDiscountCalculator()). Promo code rules are not re-evaluated.
   * - Shipping: only refunded for defective items. When the whole order comes back with at least
   *   one defective item, the full shipping fee is refunded. On a partial return, the shipping fee
   *   is split equally across every unit of the order and each approved defective unit gets its share.
   */
  public function getTotalToBeRefunded(ProductReturn $return): int
  {
    $breakdown = $this->getRefundBreakdown($return);

    return $breakdown['items'] + $breakdown['shipping'];
  }

  /**
   * getTotalToBeRefunded() split by component, each rounded up separately (XOF has no
   * minor unit) so the parts always add up to the total recorded on the Refund.
   *
   * @return array{items: int, shipping: int}
   */
  public function getRefundBreakdown(ProductReturn $return): array
  {
    $none = ['items' => 0, 'shipping' => 0];
    $order = $return->getReturnOrder();
    if (!$order) {
      return $none;
    }

    // Subtotal the discount was spread over (shipping excluded) and total units in the order.
    $orderSubtotal = 0;
    $orderUnits = 0;
    foreach ($order->getProducts() as $orderItem) {
      $quantity = $orderItem->getQuantity() ?? 0;
      $orderSubtotal += ($orderItem->getUnitPrice() ?? 0) * $quantity;
      $orderUnits += $quantity;
    }
    if ($orderSubtotal <= 0 || $orderUnits === 0) {
      return $none;
    }

    $lineDiscount = $this->lineDiscountCalculator($order, $orderSubtotal);
    $shippingFee = max((float) ($order->getShippingFee() ?? 0), 0);

    $itemsRefund = 0;
    $defectiveUnits = 0;
    foreach ($return->getReturnItems() as $item) {
      if ($item->getDecision() !== ProductReturnDecisionEnum::APPROVED->value) {
        continue;
      }
      $orderItem = $item->getOrderItem();
      if (!$orderItem) {
        continue;
      }

      $quantity = $item->getQuantity() ?? 0;
      $lineTotal = ($orderItem->getUnitPrice() ?? 0) * $quantity;
      $itemsRefund += $lineTotal - min($lineDiscount($lineTotal), $lineTotal);

      if ($item->getReason() === ProductReturnReasonEnum::DEFECTIVE->value) {
        $defectiveUnits += $quantity;
      }
    }

    $shippingRefund = 0;
    if ($shippingFee > 0 && $defectiveUnits > 0) {
      $shippingRefund = $this->isWholeOrderBeingRefunded($return)
        ? $shippingFee
        : min($shippingFee, $shippingFee / $orderUnits * $defectiveUnits);
    }

    // ceil to the highest integer since XOF has no minor units.
    return [
      'items' => (int) ceil($itemsRefund),
      'shipping' => (int) ceil($shippingRefund),
    ];
  }

  /**
   * Returns a function giving the discount to deduct from a returned line of $lineTotal.
   *
   * The promo code used on the order is the reference (codes are immutable, see PromoCodeService::terminate()).
   * Its rules are not re-checked: the order used it, so its discount applies.
   * - percentage code: the code's percentage of the line.
   * - fixed code: the fixed amount split across the order by line value.
   * - code no longer found (orders from before codes became immutable): the discount saved on the
   *   order, split by line value.
   *
   * @return callable(float): float
   */
  private function lineDiscountCalculator(Order $order, float $orderSubtotal): callable
  {
    if (!$order->getPromoCode()) {
      return static fn(float $lineTotal): float => 0.0;
    }

    $promoCode = $this->promoCodeService->getOrderPromoCode($order);
    $value = max((float) ($promoCode?->getDiscount() ?? 0), 0.0);

    if ($promoCode && $promoCode->getType() === PromoCode::TYPE_PERCENTAGE) {
      $rate = min($value, 100.0) / 100;

      return static fn(float $lineTotal): float => $lineTotal * $rate;
    }

    $orderDiscount = $promoCode ? $value : max((float) ($order->getDiscount() ?? 0), 0.0);
    $share = min($orderDiscount, $orderSubtotal) / $orderSubtotal;

    return static fn(float $lineTotal): float => $lineTotal * $share;
  }

  public function isWholeOrderBeingRefunded(ProductReturn $return): bool
  {
    $order = $return->getReturnOrder();

    // Total quantity ordered per order item
    $orderedQuantities = [];
    foreach ($order->getProducts() as $orderItem) {
      $orderedQuantities[spl_object_id($orderItem)] = $orderItem->getQuantity();
    }

    if (!$orderedQuantities) {
      return false;
    }

    // Approved quantity per order item
    $returnedQuantities = [];
    foreach ($return->getReturnItems() as $returnItem) {
      if ($returnItem->getDecision() !== ProductReturnDecisionEnum::APPROVED->value) {
        continue;
      }
      $key = spl_object_id($returnItem->getOrderItem());
      $returnedQuantities[$key] = ($returnedQuantities[$key] ?? 0) + $returnItem->getQuantity();
    }

    foreach ($orderedQuantities as $key => $quantity) {
      if (($returnedQuantities[$key] ?? 0) < $quantity) {
        return false;
      }
    }

    return true;
  }
}