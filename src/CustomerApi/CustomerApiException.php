<?php

declare(strict_types=1);

namespace SendRepute\Laravel\CustomerApi;

final class CustomerApiException extends \RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message, public readonly int $status = 0)
    {
        parent::__construct($message);
    }
}
