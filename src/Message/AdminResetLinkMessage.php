<?php

declare(strict_types=1);

namespace App\Message;

final class AdminResetLinkMessage
{
    public function __construct(
        public string $email,
        public string $fullName,
        public string $resetToken,
    ) {
    }
}
