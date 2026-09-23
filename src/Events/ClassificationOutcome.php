<?php

namespace SendRepute\Laravel\Events;

/**
 * A content-free diagnostic event. It deliberately contains no message,
 * sender, subject, body, recipient, attachment, credential, or exception.
 */
final readonly class ClassificationOutcome
{
    public function __construct(
        public string $kind,
        public string $action,
        public ?float $spamProbability = null,
        public ?string $category = null,
        public ?int $status = null,
        public ?string $apiCode = null,
        public ?string $requestId = null,
    ) {
    }
}