<?php

namespace App\Service;
use App\Entity\OrderItem;
use App\Entity\ProductReturn;
use App\Entity\ProductReturnItem;
use App\Enumeration\OrderStatusEnum;
use App\Enumeration\ProductReturnStatusEnum;
use App\Repository\OrderItemRepository;
use App\Repository\OrderRepository;
use App\Repository\ProductReturnItemRepository;
use Doctrine\ORM\EntityManagerInterface;

final class ProductReturnService
{
  public function __construct(
    private EntityManagerInterface $entityManagerInterface,
    private OrderRepository $orderRepository,
    private UniqUidGenerator $uniqUidGenerator,
    private ProductReturnItemRepository $productReturnItemRepository,
    private OrderItemRepository $orderItemRepository
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
   * Total units already returned for an order item, excluding rejected returns.
   */
  public function returnedQuantityForItem(OrderItem $orderItem): int
  {
    $total = 0;
    foreach ($this->productReturnItemRepository->findBy(['orderItem' => $orderItem]) as $returnItem) {
      if ($returnItem->getProductReturn()?->getStatus() !== ProductReturnStatusEnum::REJECTED->value) {
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
}