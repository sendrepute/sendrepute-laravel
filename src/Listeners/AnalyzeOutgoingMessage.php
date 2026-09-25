<?php

namespace SendRepute\Laravel\Listeners;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Mail\Events\MessageSending;
use SendRepute\Laravel\Contracts\Classifier;
use SendRepute\Laravel\Events\ClassificationOutcome;
use SendRepute\Laravel\Exceptions\SendReputeException;
use Symfony\Component\Mime\Email;
use Throwable;

final class AnalyzeOutgoingMessage
{
    public function __construct(
        private readonly Classifier $classifier,
        private readonly Dispatcher $events,
    ) {}

    /**
     * Returning false cancels Laravel's send from the official MessageSending
     * pre-send event. Any other return value permits the send to continue.
     */
    public function handle(MessageSending $event): ?bool
    {
        $settings = config('sendrepute.mail', []);
        $headerName = is_string($settings['opt_in_header'] ?? null)
            ? $settings['opt_in_header']
            : 'X-SendRepute-Classify';

        if (!$event->message instanceof Email) {
            // This integration cannot observe an opt-in marker on an
            // unsupported message type, so it must not become a global gate.
            return null;
        }

        $headers = $event->message->getHeaders();
        $marker = $headers->get($headerName);
        if ($marker === null) {
            return null;
        }

        // The internal consent marker must never leave the application.
        $headers->remove($headerName);
        $optedIn = strtolower(trim($marker->getBodyAsString())) === 'yes';
        if (!$optedIn) {
            return null;
        }

        if (!($settings['enabled'] ?? false) || !config('sendrepute.paid_analysis_consent', false)) {
            return $this->failureDecision($settings);
        }

        try {
            $this->validatePolicy($settings);
            $sender = $this->sender($event->message);
            $subject = $event->message->getSubject();
            $alternatives = $this->alternatives($event->message);
            $body = $alternatives[0]['body'];
            if (!is_string($subject)) {
                throw new \UnexpectedValueException('Subject and body must be in-memory strings.');
            }
            $model = $settings['model'] ?? null;
            $classification = $this->classifier->classify(
                $sender,
                $subject,
                $body,
                is_string($model) ? $model : null,
                displayedAlternatives: $alternatives,
            );
        } catch (Throwable $error) {
            $mustBlock = $error instanceof SendReputeException
                && ($error->apiCode === 'PRICE_CHANGED' || $error->category === 'price_changed');
            $this->reportFailure($error, $settings, $mustBlock);
            if ($mustBlock) {
                return false;
            }
            return $this->failureDecision($settings);
        }

        $blocked = ($settings['mode'] ?? null) === 'blocking'
            && $classification->spamProbability >= (float) $settings['spam_probability_threshold'];
        $this->report(new ClassificationOutcome(
            'classification',
            $blocked ? 'blocked' : 'allowed',
            $classification->spamProbability,
            requestId: $classification->requestId,
        ));

        return $blocked ? false : null;
    }

    private function sender(Email $message): string
    {
        $from = $message->getFrom()[0] ?? null;
        $name = $from?->getName();
        if (!is_string($name) || trim($name) === '') {
            throw new \UnexpectedValueException('A sender display name is required.');
        }
        return trim($name);
    }

    private function alternatives(Email $message): array
    {
        $text = $message->getTextBody();
        $html = $message->getHtmlBody();
        $alternatives = [];
        if ($text !== null) {
            $alternatives[] = ['contentType' => 'text/plain', 'body' => $text];
        }
        if ($html !== null) {
            $alternatives[] = ['contentType' => 'text/html', 'body' => $html];
        }
        if ($alternatives !== []) {
            return $alternatives;
        }
        throw new \UnexpectedValueException('An in-memory text or HTML body is required.');
    }

    private function validatePolicy(array $settings): void
    {
        $mode = $settings['mode'] ?? null;
        $failure = $settings['failure_policy'] ?? null;
        $threshold = $settings['spam_probability_threshold'] ?? null;
        if (!in_array($mode, ['advisory', 'blocking'], true)
            || !in_array($failure, ['allow', 'block'], true)
            || !is_numeric($threshold) || !is_finite((float) $threshold)
            || $threshold < 0 || $threshold > 1) {
            throw new \UnexpectedValueException('Invalid SendRepute mail policy.');
        }
    }

    private function failureDecision(array $settings): ?bool
    {
        return ($settings['failure_policy'] ?? 'allow') === 'block' ? false : null;
    }

    private function reportFailure(Throwable $error, array $settings, bool $forceBlock = false): void
    {
        $blocked = $forceBlock || $this->failureDecision($settings) === false;
        if ($error instanceof SendReputeException) {
            $this->report(new ClassificationOutcome(
                'api_failure',
                $blocked ? 'blocked' : 'allowed',
                category: $error->category,
                status: $error->status,
                apiCode: $error->apiCode,
                requestId: $error->requestId,
            ));
            return;
        }
        $this->report(new ClassificationOutcome(
            'api_failure',
            $blocked ? 'blocked' : 'allowed',
            category: $error instanceof \UnexpectedValueException ? 'malformed_message' : 'internal',
        ));
    }

    private function report(ClassificationOutcome $outcome): void
    {
        try {
            $this->events->dispatch($outcome);
        } catch (Throwable) {
            // A diagnostic observer must never alter the configured mail policy.
        }
    }
}