<?php


declare(strict_types=1);

namespace App\Message;

readonly class WelcomeMessage
{
  public function __construct(
    public string $email,
    public string $fullName,
    public ?string $discountCode = null,
    public ?string $discountExpirationDate = null,
    public ?string $discountAmount = null
  ) {
  }
}