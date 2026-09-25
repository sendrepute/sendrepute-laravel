<?php

namespace SendRepute\Laravel\Contracts;

use SendRepute\Laravel\ClassificationResult;

interface Classifier
{
    public function classify(
        string $sender,
        string $subject,
        string $body,
        ?string $model = null,
        bool $paidAnalysisConsent = false,
        ?array $displayedAlternatives = null,
    ): ClassificationResult;
}