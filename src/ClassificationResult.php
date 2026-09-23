<?php

namespace SendRepute\Laravel;

final readonly class ClassificationResult
{
    public function __construct(
        public string $requestId,
        public string $model,
        public string $label,
        public float $spamProbability,
        public string $confidence,
        public bool $replayed,
        public int $chargedMillicents,
    ) {
    }
}