<?php

declare(strict_types=1);

namespace SendRepute\Laravel\CustomerApi;

final class IntentConflict extends \RuntimeException
{
    public function __construct(public readonly int $status, public readonly string $errorCode, string $message, public readonly ?array $record = null)
    {
        parent::__construct($message);
    }
}
