<?php
declare(strict_types=1);

namespace App\Message;

class NewProductAddedMessage
{
  public function __construct(
    public string $uid
  ) {
  }
}