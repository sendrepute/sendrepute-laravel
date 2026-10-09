<?php

declare(strict_types=1);

namespace SendRepute\Laravel\CustomerApi;

/**
 * Programmatic client for every customer API operation. The bearer key stays
 * server-side; the destination is HTTPS on an allowlisted host; redirects are
 * refused; responses are size-capped; nothing is retried automatically.
 *
 * Usage: $client->call('customerGetAccount');
 *        $client->call('customerPurchaseVip', ['body' => [...]], ['paidConsent' => ['acknowledged' => true, 'expectedPriceMillicents' => 1900]]);
 */
final class CustomerApiClient
{
    public const DEFAULT_BASE_URL = 'https://www.sendrepute.com/api';

    private readonly string $baseUrl;

    /** @param list<string> $trustedHosts additional exact hosts accepted for base_url */
    public function __construct(
        private readonly Transport $transport,
        #[\SensitiveParameter] private readonly string $apiKey,
        ?string $baseUrl = null,
        array $trustedHosts = [],
        private readonly float $timeoutSeconds = 20.0,
        private readonly int $maxResponseBytes = 8388608,
    ) {
        if (preg_match('/\A[A-Za-z0-9._~+\/=-]{16,512}\z/', $apiKey) !== 1) {
            throw new CustomerApiException('configuration', 'A SendRepute customer API key is required.');
        }
        $base = rtrim($baseUrl ?? self::DEFAULT_BASE_URL, '/');
        $parts = parse_url($base);
        $hosts = array_merge(['www.sendrepute.com'], array_values(array_filter($trustedHosts, 'is_string')));
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || !in_array(strtolower($parts['host'] ?? ''), array_map('strtolower', $hosts), true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new CustomerApiException('configuration', 'Customer API base URL must be HTTPS on an exact trusted host.');
        }
        if ($timeoutSeconds < 1.0 || $timeoutSeconds > 120.0 || $maxResponseBytes < 1024 || $maxResponseBytes > 16777216) {
            throw new CustomerApiException('configuration', 'Customer API timeout or response limit is out of range.');
        }
        $this->baseUrl = $base;
    }

    /** One-way identity of the configured credential, used to scope durable intents. */
    public function credentialFingerprint(): string
    {
        return hash('sha256', "sendrepute-credential-v1\n".$this->apiKey);
    }

    public function __debugInfo(): array
    {
        return ['baseUrl' => $this->baseUrl];
    }

    /** @return array{status:int, ok:bool, data:mixed, rateLimit:array<string,?int>} */
    public function call(string $operationId, array $input = [], array $options = []): array
    {
        return $this->send(CallPolicy::prepare($operationId, $input, $options));
    }

    /** @param array{op:array,method:string,path:string,body:?string} $prepared */
    public function send(array $prepared): array
    {
        $headers = ['Accept' => 'application/json', 'Authorization' => 'Bearer '.$this->apiKey];
        if ($prepared['body'] !== null) {
            $headers['Content-Type'] = 'application/json';
        }
        $response = $this->transport->send($prepared['method'], $this->baseUrl.$prepared['path'], $headers, $prepared['body'], $this->timeoutSeconds, $this->maxResponseBytes);
        $status = $response['status'];
        if ($status >= 300 && $status < 400) {
            throw new CustomerApiException('transport', 'Redirects are refused to protect the API key.', $status);
        }
        $lower = array_change_key_case($response['headers'], CASE_LOWER);
        $data = null;
        if ($response['body'] !== '') {
            if (preg_match('#\Aapplication/([a-z.+-]*\+)?json\b#i', $lower['content-type'] ?? '') !== 1) {
                throw new CustomerApiException('malformed_response', 'SendRepute returned a non-JSON response.', $status);
            }
            try {
                $data = json_decode($response['body'], true, 128, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw new CustomerApiException('malformed_response', 'SendRepute returned invalid JSON.', $status);
            }
        }
        $num = static fn (string $name): ?int => isset($lower[$name]) && preg_match('/\A\d{1,12}\z/', $lower[$name]) === 1 ? (int) $lower[$name] : null;

        return [
            'status' => $status,
            'ok' => $status >= 200 && $status < 300,
            'data' => $data,
            'rateLimit' => [
                'limit' => $num('x-ratelimit-limit'),
                'remaining' => $num('x-ratelimit-remaining'),
                'reset' => $num('x-ratelimit-reset'),
                'retryAfter' => $num('retry-after'),
            ],
        ];
    }
}
