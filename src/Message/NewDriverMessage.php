<?php

namespace App\Message;

class NewDriverMessage
{
  public function __construct(
    public string $email,
    public string $temporaryPassword,
    public string $fullName
  ) {
  }
}