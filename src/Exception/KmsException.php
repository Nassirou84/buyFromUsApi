<?php

declare(strict_types=1);

namespace App\Exception;

use RuntimeException;
use Throwable;

/**
 * Raised when a Cloud KMS operation fails or returns data that cannot be trusted.
 *
 * The message never carries plaintext or ciphertext so it is safe to log.
 */
final class KmsException extends RuntimeException
{
    public function __construct(string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
