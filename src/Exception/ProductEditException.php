<?php

declare(strict_types=1);

namespace App\Exception;

use RuntimeException;
use Throwable;

final class ProductEditException extends RuntimeException
{
    /**
     * @param array<string, string> $violations validation messages indexed by property path
     */
    public function __construct(
        string $message,
        private readonly int $statusCode,
        private readonly array $violations = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * @return array<string, string>
     */
    public function getViolations(): array
    {
        return $this->violations;
    }
}
