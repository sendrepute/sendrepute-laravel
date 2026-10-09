<?php

declare(strict_types=1);

namespace SendRepute\Laravel\CustomerApi;

interface Transport
{
    /**
     * Perform one HTTPS request without following redirects.
     *
     * @param array<string,string> $headers
     * @return array{status:int, headers:array<string,string>, body:string}
     * @throws CustomerApiException on transport failure or oversize responses
     */
    public function send(string $method, string $url, array $headers, ?string $body, float $timeout, int $maxBytes): array;
}
