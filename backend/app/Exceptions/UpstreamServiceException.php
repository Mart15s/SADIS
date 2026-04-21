<?php

namespace App\Exceptions;

use RuntimeException;

class UpstreamServiceException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $provider,
        public readonly string $context,
        public readonly int $status = 0,
        public readonly ?int $retryAfterSeconds = null,
    ) {
        parent::__construct($message, $status);
    }
}
