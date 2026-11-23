<?php

namespace App\Message;

final class NewReturnRequestMessage
{
  /**
   * @param list<array{title: string, image: string, variant: string, quantity: int, reason: string}> $items
   */
  public function __construct(
    public string $email,
    public string $returnRef,
    public string $fullName,
    public string $orderRef,
    public string $createdAt,
    public array $items,
    public string $refundType,
    public string $status,
    public ?string $codeQr = null,
    public ?string $additionalDetails = null
  ) {
  }
}
