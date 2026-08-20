<?php

declare(strict_types=1);

namespace App\Message;

readonly class TwoFactorCodeMessage
{
    public function __construct(
        public string $email,
        public string $authCode,
        public string $fullName,
    ) {
    }
}
