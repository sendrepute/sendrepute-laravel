<?php

declare(strict_types=1);

namespace SendRepute\Laravel\CustomerApi;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

final class GuzzleTransport implements Transport
{
    public function __construct(private readonly ClientInterface $http)
    {
    }

    public function send(string $method, string $url, array $headers, ?string $body, float $timeout, int $maxBytes): array
    {
        try {
            $response = $this->http->request($method, $url, [
                'allow_redirects' => false,
                'connect_timeout' => min(5.0, $timeout),
                'timeout' => $timeout,
                'read_timeout' => $timeout,
                'verify' => true,
                'stream' => true,
                'http_errors' => false,
                'headers' => $headers,
                'body' => $body,
            ]);
        } catch (GuzzleException) {
            // Never chain the Guzzle exception: its request carries the Authorization header.
            throw new CustomerApiException('transport', 'SendRepute request failed before a response was received. It was not retried.');
        }
        $length = $response->getHeaderLine('Content-Length');
        if ($length !== '' && ctype_digit($length) && (int) $length > $maxBytes) {
            throw new CustomerApiException('response_too_large', 'Response exceeds the configured size limit.', $response->getStatusCode());
        }
        $stream = $response->getBody();
        $raw = '';
        $deadline = hrtime(true) + (int) ($timeout * 1e9);
        while (!$stream->eof()) {
            if (hrtime(true) > $deadline) {
                throw new CustomerApiException('transport', 'Response body timed out. It was not retried.', $response->getStatusCode());
            }
            try {
                $chunk = $stream->read(8192);
            } catch (\Throwable) {
                throw new CustomerApiException('transport', 'Response body could not be read. It was not retried.', $response->getStatusCode());
            }
            $raw .= $chunk;
            if (strlen($raw) > $maxBytes) {
                throw new CustomerApiException('response_too_large', 'Response exceeds the configured size limit.', $response->getStatusCode());
            }
            if ($chunk === '' && !$stream->eof()) {
                break;
            }
        }
        $out = [];
        foreach (['Content-Type', 'X-RateLimit-Limit', 'X-RateLimit-Remaining', 'X-RateLimit-Reset', 'Retry-After'] as $name) {
            if ($response->hasHeader($name)) {
                $out[$name] = $response->getHeaderLine($name);
            }
        }

        return ['status' => $response->getStatusCode(), 'headers' => $out, 'body' => $raw];
    }
}
