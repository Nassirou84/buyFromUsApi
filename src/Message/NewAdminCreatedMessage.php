<?php

declare(strict_types=1);

namespace App\Message;

final class NewAdminCreatedMessage
{
    public function __construct(
        public string $adminEmail,
        public string $fullName,
        public string $resetToken,
        public int $expiryMinutes,
    ) {
    }
}
