<?php

namespace App\Message;

class NewShoppingReequestMessage
{
  public function __construct(
    public string $email,
    public string $title,
    public string $description,
    public string $fullName,
    public string $phoneNumber,
    public string $address,
    public string $createdAt,
    public string $uid
  ) {
  }
}