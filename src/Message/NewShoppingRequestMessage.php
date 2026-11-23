<?php

namespace App\Message;

class NewShoppingRequestMessage
{
  public function __construct(
    public string $email,
    public string $title,
    public string $description,
    public string $fullName,
    public string $phoneNumber,
    public string $address,
    public string $createdAt,
    public string $uid,
    public string $preferredContact,
    public int $quantity,
    public string $accessToken,
  ) {
  }
}