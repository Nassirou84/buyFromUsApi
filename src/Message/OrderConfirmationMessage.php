<?php

declare(strict_types=1);

namespace App\Message;

readonly class OrderConfirmationMessage
{
  public function __construct(
    public string $orderId,
    public string $email,
    public string $fullName,
    public string $transactionId,
    public string $estimatedDelivery,
    public string $shippingType,
    public float $totalAmount,
    public float $taxAmount,
    public float $subTotal,
    public float $deliveryFee,
    public float $discountAmount,
    public string $paymentStatus,
    public array $items,
    public array $shippingAddress,
    public string $paymentMethod,
    public ?string $paymentLast4 = null,
  ) {
  }
}