<?php

namespace SendRepute\Laravel;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use JsonException;
use SendRepute\Laravel\Contracts\Classifier;
use SendRepute\Laravel\Exceptions\SendReputeException;
use Throwable;

final class SendReputeClassifier implements Classifier
{
    private const MODELS = ['thor', 'theos', 'athena', 'odin', 'freya', 'hermes', 'ares', 'apollo'];
    private const PRICE_FIELDS = [
        'classificationBaseMillicents',
        'includedUniqueTerms',
        'additionalTermMillicents',
        'maximumClassificationMillicents',
    ];

    public function __construct(
        private readonly ClientInterface $http,
        private readonly array $config,
    ) {
    }

    public function classify(
        string $sender,
        string $subject,
        string $body,
        ?string $model = null,
        bool $paidAnalysisConsent = false,
        ?array $displayedAlternatives = null,
    ): ClassificationResult {
        if (!$paidAnalysisConsent && !($this->config['paid_analysis_consent'] ?? false)) {
            throw new SendReputeException('consent_required', 'Paid SendRepute analysis has not been explicitly enabled.');
        }

        $this->validateInput($sender, $subject, $body, $model, $displayedAlternatives);
        [$baseUrl, $apiKey] = $this->validatedConnection();
        $connectTimeout = $this->boundedFloat('connect_timeout_seconds', 3.0, 0.1, 30.0);
        $timeout = $this->boundedFloat('timeout_seconds', 10.0, 0.1, 60.0);
        $maximum = (int) ($this->config['max_response_bytes'] ?? 1048576);
        if ($maximum < 1024 || $maximum > 4194304) {
            throw new SendReputeException('configuration', 'max_response_bytes must be between 1024 and 4194304.');
        }
        $payload = ['sender' => $sender, 'subject' => $subject, 'body' => $body];
        if ($displayedAlternatives !== null) {
            $payload['displayedAlternatives'] = $displayedAlternatives;
        }
        if ($model !== null) {
            $payload['model'] = $model;
        }
        $authorization = $this->priceAuthorization();
        if ($authorization !== null) {
            $payload['priceAuthorization'] = $authorization;
        }

        try {
            $response = $this->http->request('POST', $baseUrl.'/v1/classify', [
                'allow_redirects' => false,
                'connect_timeout' => $connectTimeout,
                'timeout' => $timeout,
                'read_timeout' => $timeout,
                'verify' => true,
                'stream' => true,
                'headers' => [
                    'Accept' => 'application/json',
                    'Authorization' => 'Bearer '.$apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => $payload,
                'http_errors' => false,
            ]);
        } catch (GuzzleException) {
            // Do not retain Guzzle's request object as an exception cause: it
            // contains the Authorization header and may later be logged.
            throw new SendReputeException('transport', 'SendRepute request failed before a valid response was received.');
        }

        $status = $response->getStatusCode();
        if ($status >= 300 && $status < 400) {
            throw new SendReputeException('transport', 'SendRepute redirects are refused to protect credentials.', $status);
        }

        $length = $response->getHeaderLine('Content-Length');
        if ($length !== '' && ctype_digit($length) && (int) $length > $maximum) {
            throw new SendReputeException('malformed_response', 'SendRepute response exceeded the configured size limit.', $status);
        }
        $stream = $response->getBody();
        $raw = '';
        $deadline = hrtime(true) + (int) ($timeout * 1_000_000_000);
        while (!$stream->eof()) {
            if (hrtime(true) > $deadline) {
                throw new SendReputeException('transport', 'SendRepute response body timed out.', $status);
            }
            try {
                $chunk = $stream->read(min(8192, $maximum - strlen($raw) + 1));
            } catch (Throwable) {
                throw new SendReputeException('transport', 'SendRepute response body could not be read.', $status);
            }
            if ($chunk === '' && !$stream->eof()) {
                throw new SendReputeException('transport', 'SendRepute response body stopped before completion.', $status);
            }
            $raw .= $chunk;
            if (strlen($raw) > $maximum) {
                throw new SendReputeException('malformed_response', 'SendRepute response exceeded the configured size limit.', $status);
            }
        }

        if ($status < 200 || $status >= 300) {
            try {
                $candidate = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
                $decoded = is_array($candidate) ? $candidate : [];
            } catch (JsonException) {
                $decoded = [];
            }
            throw $this->apiError($status, $decoded);
        }
        try {
            $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new SendReputeException('malformed_response', 'SendRepute returned invalid JSON.', $status, previous: $error);
        }
        if (!is_array($decoded)) {
            throw new SendReputeException('malformed_response', 'SendRepute returned an invalid response object.', $status);
        }

        $result = $this->result($decoded);
        if ($authorization !== null && !$result->replayed
            && $result->chargedMillicents > $authorization['maxChargeMillicents']) {
            throw new SendReputeException('malformed_response', 'SendRepute reported a charge above the authorized per-request maximum.', $status);
        }

        return $result;
    }

    private function validateInput(string $sender, string $subject, string $body, ?string $model, ?array $displayedAlternatives): void
    {
        foreach ([[$sender, 320, 'sender'], [$subject, 998, 'subject'], [$body, 524288, 'body']] as [$value, $limit, $field]) {
            if ($value === '' || strlen($value) > $limit || preg_match('//u', $value) !== 1) {
                throw new SendReputeException('malformed_request', "{$field} must be non-empty and at most {$limit} bytes.");
            }
        }
        if ($model !== null && !in_array($model, self::MODELS, true)) {
            throw new SendReputeException('malformed_request', 'The selected model is not supported.');
        }
        if ($displayedAlternatives !== null) {
            if ($displayedAlternatives === [] || count($displayedAlternatives) > 2) {
                throw new SendReputeException('malformed_request', 'displayedAlternatives must contain one or two displayed parts.');
            }
            $combinedBytes = strlen($body);
            foreach ($displayedAlternatives as $alternative) {
                if (!is_array($alternative) || array_keys($alternative) !== ['contentType', 'body']
                    || !in_array($alternative['contentType'] ?? null, ['text/plain', 'text/html'], true)
                    || !is_string($alternative['body'] ?? null) || $alternative['body'] === ''
                    || preg_match('//u', $alternative['body']) !== 1) {
                    throw new SendReputeException('malformed_request', 'Each displayed alternative must have an exact supported contentType and non-empty UTF-8 body.');
                }
                $combinedBytes += strlen($alternative['body']);
            }
            if ($combinedBytes > 524288) {
                throw new SendReputeException('malformed_request', 'The compatibility body and displayed alternatives exceed 524288 bytes.');
            }
        }
    }

    private function priceAuthorization(): ?array
    {
        $settings = $this->config['price_authorization'] ?? null;
        if (!is_array($settings) || !($settings['enabled'] ?? false)) {
            return null;
        }
        $configured = $settings['expected_pricing'] ?? null;
        if (!is_array($configured)) {
            throw new SendReputeException('configuration', 'Expected classification pricing must be configured deliberately.');
        }
        $expectedPricing = [];
        foreach (self::PRICE_FIELDS as $field) {
            $expectedPricing[$field] = $this->configurationInteger(
                $configured[$field] ?? null,
                "price_authorization.expected_pricing.{$field}",
            );
        }
        $ceiling = $this->configurationInteger(
            $settings['maximum_charge_millicents'] ?? null,
            'price_authorization.maximum_charge_millicents',
        );

        return ['expectedPricing' => $expectedPricing, 'maxChargeMillicents' => $ceiling];
    }

    private function configurationInteger(mixed $value, string $key): int
    {
        if (is_string($value) && preg_match('/\A(?:0|[1-9][0-9]{0,15})\z/', $value) === 1) {
            $value = (int) $value;
        }
        if (!is_int($value) || $value < 0 || $value > 9007199254740991) {
            throw new SendReputeException('configuration', "{$key} must be an integer from 0 through 9007199254740991.");
        }
        return $value;
    }

    private function validatedConnection(): array
    {
        $key = $this->config['api_key'] ?? null;
        if (!is_string($key) || trim($key) === '' || preg_match('/[\r\n]/', $key)) {
            throw new SendReputeException('configuration', 'A valid SendRepute API key is required.');
        }
        $url = $this->config['base_url'] ?? null;
        if (!is_string($url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new SendReputeException('configuration', 'An absolute SendRepute base URL is required.');
        }
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $trusted = $this->config['trusted_hosts'] ?? [];
        $validHosts = is_array($trusted)
            ? array_map(static fn ($item) => strtolower((string) $item), $trusted)
            : [];
        if (($parts['scheme'] ?? null) !== 'https' || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment']) || $host === ''
            || !in_array($host, $validHosts, true)) {
            throw new SendReputeException('configuration', 'The base URL must be HTTPS, credential-free, query-free, and use an explicitly trusted host.');
        }
        foreach ($validHosts as $allowed) {
            if ($allowed === '' || str_contains($allowed, '*') || filter_var($allowed, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
                throw new SendReputeException('configuration', 'trusted_hosts must contain only exact valid hostnames.');
            }
        }

        return [rtrim($url, '/'), $key];
    }

    private function boundedFloat(string $key, float $default, float $minimum, float $maximum): float
    {
        $value = $this->config[$key] ?? $default;
        if (!is_numeric($value) || !is_finite((float) $value) || $value < $minimum || $value > $maximum) {
            throw new SendReputeException('configuration', "{$key} is outside its allowed range.");
        }
        return (float) $value;
    }

    private function apiError(int $status, array $decoded): SendReputeException
    {
        $rawCode = $decoded['error']['code'] ?? null;
        $code = is_string($rawCode) && preg_match('/\A[A-Za-z0-9._:-]{1,128}\z/', $rawCode) === 1
            ? $rawCode
            : null;
        $rawRequestId = $decoded['requestId'] ?? null;
        $requestId = is_string($rawRequestId) && preg_match('/\A[A-Za-z0-9._:-]{1,128}\z/', $rawRequestId) === 1
            ? $rawRequestId
            : null;
        $category = $status === 409 && $code === 'PRICE_CHANGED'
            ? 'price_changed'
            : match ($status) {
                400, 413 => 'malformed_request',
                401, 403 => 'authentication',
                402 => 'balance',
                429 => 'rate_limit',
                409, 503 => 'unavailable',
                default => 'api',
            };
        return new SendReputeException($category, "SendRepute request failed with HTTP status {$status}.", $status, $code, $requestId);
    }

    private function result(array $value): ClassificationResult
    {
        $result = $value['result'] ?? null;
        $billing = $value['billing'] ?? null;
        $score = is_array($result) ? ($result['spamProbability'] ?? null) : null;
        if (!is_array($result) || !is_array($billing)
            || !isset($value['requestId'], $value['model'], $result['label'], $result['confidence'])
            || !is_string($value['requestId']) || !is_string($value['model'])
            || !in_array($result['label'], ['inbox', 'spam'], true)
            || !in_array($result['confidence'], ['low', 'medium', 'high'], true)
            || !is_int($score) && !is_float($score)
            || !is_finite((float) $score) || $score < 0 || $score > 1
            || !is_bool($billing['replayed'] ?? null)
            || (!is_int($billing['chargedMillicents'] ?? null) && !is_float($billing['chargedMillicents'] ?? null))
            || !is_finite((float) ($billing['chargedMillicents'] ?? NAN))
            || $billing['chargedMillicents'] < 0 || floor((float) $billing['chargedMillicents']) !== (float) $billing['chargedMillicents']) {
            throw new SendReputeException('malformed_response', 'SendRepute returned an invalid classification result.');
        }
        return new ClassificationResult(
            $value['requestId'],
            $value['model'],
            $result['label'],
            (float) $score,
            $result['confidence'],
            $billing['replayed'],
            (int) $billing['chargedMillicents'],
        );
    }
}