<?php

namespace SendRepute\Laravel\Exceptions;

use RuntimeException;
use Throwable;

final class SendReputeException extends RuntimeException
{
    public function __construct(
        public readonly string $category,
        string $message,
        public readonly ?int $status = null,
        public readonly ?string $apiCode = null,
        public readonly ?string $requestId = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}