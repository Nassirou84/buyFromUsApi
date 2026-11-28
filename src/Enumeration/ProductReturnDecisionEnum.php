<?php

declare(strict_types=1);

namespace App\Enumeration;

enum ProductReturnDecisionEnum: string
{
    case APPROVED = 'approved';
    case REJECTED = 'rejected';
}
