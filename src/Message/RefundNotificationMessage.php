<?php

declare(strict_types=1);

namespace App\Message;

final readonly class RefundNotificationMessage
{
    public function __construct(
        public int $refundId,
        public string $status,
        public string $source = 'api', // 'webhook' | 'sync' | 'api' | 'manual'
    ) {
    }
}
