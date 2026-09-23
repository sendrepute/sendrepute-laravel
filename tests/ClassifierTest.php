<?php

namespace SendRepute\Laravel\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SendRepute\Laravel\Exceptions\SendReputeException;
use SendRepute\Laravel\SendReputeClassifier;

final class ClassifierTest extends TestCase
{
    private function classifier(array $responses, array $changes = []): SendReputeClassifier
    {
        $client = new Client(['handler' => HandlerStack::create(new MockHandler($responses))]);
        return new SendReputeClassifier($client, array_replace([
            'paid_analysis_consent' => false,
            'api_key' => 'test-secret',
            'base_url' => 'https://api.example.test',
            'trusted_hosts' => ['api.example.test'],
            'timeout_seconds' => 1,
            'connect_timeout_seconds' => 1,
            'max_response_bytes' => 4096,
        ], $changes));
    }

    public function test_explicit_per_call_consent_and_valid_response(): void
    {
        $body = json_encode([
            'requestId' => 'req-1',
            'model' => 'thor',
            'result' => ['label' => 'spam', 'spamProbability' => 0.75, 'confidence' => 'high'],
            'billing' => ['replayed' => false, 'chargedMillicents' => 10],
        ], JSON_THROW_ON_ERROR);
        $result = $this->classifier([new Response(200, [], $body)])
            ->classify('Example', 'Subject', 'Body', paidAnalysisConsent: true);

        self::assertSame(0.75, $result->spamProbability);
        self::assertSame('spam', $result->label);
    }

    public function test_paid_call_is_disabled_by_default(): void
    {
        $this->expectException(SendReputeException::class);
        $this->expectExceptionMessage('explicitly enabled');
        $this->classifier([])->classify('Example', 'Subject', 'Body');
    }

    public function test_score_outside_zero_to_one_is_malformed_not_spam(): void
    {
        $body = json_encode([
            'requestId' => 'req-1',
            'model' => 'thor',
            'result' => ['label' => 'spam', 'spamProbability' => 1.01, 'confidence' => 'high'],
            'billing' => ['replayed' => false, 'chargedMillicents' => 10],
        ], JSON_THROW_ON_ERROR);
        try {
            $this->classifier([new Response(200, [], $body)])
                ->classify('Example', 'Subject', 'Body', paidAnalysisConsent: true);
            self::fail('Expected malformed response');
        } catch (SendReputeException $error) {
            self::assertSame('malformed_response', $error->category);
        }
    }

    public function test_non_json_authentication_error_keeps_auth_category(): void
    {
        try {
            $this->classifier([new Response(401, [], '<html>unauthorized</html>')])
                ->classify('Example', 'Subject', 'Body', paidAnalysisConsent: true);
            self::fail('Expected authentication error');
        } catch (SendReputeException $error) {
            self::assertSame('authentication', $error->category);
            self::assertSame(401, $error->status);
        }
    }

    public function test_response_limit_configuration_is_validated_before_request(): void
    {
        $handler = new MockHandler([new Response(200, [], '{}')]);
        $client = new Client(['handler' => HandlerStack::create($handler)]);
        $classifier = new SendReputeClassifier($client, [
            'api_key' => 'test-secret',
            'base_url' => 'https://api.example.test',
            'trusted_hosts' => ['api.example.test'],
            'max_response_bytes' => 1,
        ]);
        try {
            $classifier->classify('Example', 'Subject', 'Body', paidAnalysisConsent: true);
            self::fail('Expected configuration error');
        } catch (SendReputeException $error) {
            self::assertSame('configuration', $error->category);
            self::assertSame(1, $handler->count(), 'No mock response should be consumed.');
        }
    }

    public function test_chunked_response_is_bounded_and_streaming_is_requested(): void
    {
        $history = [];
        $stack = HandlerStack::create(new MockHandler([new Response(200, [], str_repeat('x', 2048))]));
        $stack->push(\GuzzleHttp\Middleware::history($history));
        $client = new Client(['handler' => $stack]);
        $classifier = new SendReputeClassifier($client, [
            'api_key' => 'test-secret',
            'base_url' => 'https://api.example.test',
            'trusted_hosts' => ['api.example.test'],
            'timeout_seconds' => 1,
            'connect_timeout_seconds' => 1,
            'max_response_bytes' => 1024,
        ]);
        try {
            $classifier->classify('Example', 'Subject', 'Body', paidAnalysisConsent: true);
            self::fail('Expected bounded response failure');
        } catch (SendReputeException $error) {
            self::assertSame('malformed_response', $error->category);
            self::assertTrue($history[0]['options']['stream']);
        }
    }

    #[DataProvider('statusCategories')]
    public function test_api_errors_are_not_classification_results(int $status, string $category): void
    {
        $body = '{"error":{"code":"TEST"},"requestId":"safe-id"}';
        try {
            $this->classifier([new Response($status, [], $body)])
                ->classify('Example', 'Subject', 'Body', paidAnalysisConsent: true);
            self::fail('Expected API error');
        } catch (SendReputeException $error) {
            self::assertSame($category, $error->category);
            self::assertSame('safe-id', $error->requestId);
        }
    }

    public static function statusCategories(): array
    {
        return [[400, 'malformed_request'], [401, 'authentication'], [402, 'balance'], [429, 'rate_limit'], [503, 'unavailable']];
    }

    public function test_untrusted_or_non_https_origins_are_refused_before_network(): void
    {
        foreach ([
            ['base_url' => 'http://api.example.test'],
            ['base_url' => 'https://other.example.test'],
            ['base_url' => 'https://user:pass@api.example.test'],
            ['base_url' => 'https://api.example.test?token=no'],
        ] as $change) {
            try {
                $this->classifier([], $change)->classify('Example', 'Subject', 'Body', paidAnalysisConsent: true);
                self::fail('Expected unsafe base URL to fail');
            } catch (SendReputeException $error) {
                self::assertSame('configuration', $error->category);
            }
        }
    }
}