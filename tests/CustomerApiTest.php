<?php

declare(strict_types=1);

namespace SendRepute\Laravel\Tests;

use SendRepute\Laravel\CustomerApi\CallPolicy;
use SendRepute\Laravel\CustomerApi\ConsoleService;
use SendRepute\Laravel\CustomerApi\ConsoleSession;
use SendRepute\Laravel\CustomerApi\CustomerApiClient;
use SendRepute\Laravel\CustomerApi\CustomerApiException;
use SendRepute\Laravel\CustomerApi\FileIntentStore;
use SendRepute\Laravel\CustomerApi\OperationCatalog;
use SendRepute\Laravel\CustomerApi\PolicyException;
use SendRepute\Laravel\CustomerApi\Transport;
use PHPUnit\Framework\TestCase;

final class FakeCustomerTransport implements Transport
{
    /** @var list<array{method:string,url:string,headers:array,body:?string}> */
    public array $calls = [];

    /** @param \Closure(int):array $responder */
    public function __construct(private readonly \Closure $responder)
    {
    }

    public static function json(mixed $data, int $status = 200): self
    {
        return new self(static fn (): array => ['status' => $status, 'headers' => ['Content-Type' => 'application/json', 'X-RateLimit-Remaining' => '41'], 'body' => json_encode($data, JSON_THROW_ON_ERROR)]);
    }

    public function send(string $method, string $url, array $headers, ?string $body, float $timeout, int $maxBytes): array
    {
        $this->calls[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];

        return ($this->responder)(count($this->calls));
    }
}

final class ArrayConsoleSession implements ConsoleSession
{
    public array $data = [];

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function put(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }
}

final class CustomerApiTest extends TestCase
{
    private const KEY = 'sr_live_test_key_0123456789abcdef';

    public function test_catalog_matches_the_customer_openapi_contract(): void
    {
        $ops = OperationCatalog::operations();
        self::assertCount(49, $ops);
        $spec = __DIR__.'/../../../artifacts/api-server/src/customer-api-openapi.json';
        if (!is_file($spec)) {
            self::markTestSkipped('contract not present in this checkout');
        }
        $doc = json_decode((string) file_get_contents($spec), true, 512, JSON_THROW_ON_ERROR);
        $expected = [];
        foreach ($doc['paths'] as $path => $item) {
            foreach ($item as $method => $op) {
                $expected[] = strtoupper($method).' '.$path.' '.$op['operationId'];
            }
        }
        $actual = array_map(static fn (array $o): string => $o['method'].' '.$o['path'].' '.$o['id'], $ops);
        sort($expected);
        sort($actual);
        self::assertSame($expected, $actual);
    }

    public function test_client_uses_fixed_origin_and_hides_the_key(): void
    {
        $t = FakeCustomerTransport::json(['id' => 'acct']);
        $client = new CustomerApiClient($t, self::KEY);
        $r = $client->call('customerGetAccount');
        self::assertSame(200, $r['status']);
        self::assertSame(41, $r['rateLimit']['remaining']);
        self::assertSame('https://www.sendrepute.com/api/v1/account', $t->calls[0]['url']);
        self::assertSame('Bearer '.self::KEY, $t->calls[0]['headers']['Authorization']);
        self::assertStringNotContainsString(self::KEY, print_r($client, true));
    }

    public function test_untrusted_base_url_is_refused(): void
    {
        $this->expectException(CustomerApiException::class);
        new CustomerApiClient(FakeCustomerTransport::json([]), self::KEY, 'https://evil.example/api');
    }

    public function test_policy_validates_params_query_and_body(): void
    {
        $cases = [
            ['customerGetPaidResult', ['params' => ['recoveryId' => '../admin']], 'INVALID_PARAMETER'],
            ['customerGetCreditLedger', ['query' => ['limit' => 51]], 'INVALID_PARAMETER'],
            ['customerGetCreditLedger', ['query' => ['host' => 'evil']], 'UNKNOWN_PARAMETER'],
            ['customerStandardBuilderCompile', ['body' => ['mjml' => '<mjml/>', 'url' => 'https://x']], 'UNKNOWN_FIELD'],
            ['customerStandardBuilderCompile', ['body' => ['mjml' => str_repeat('x', 600 * 1024)]], 'BODY_TOO_LARGE'],
        ];
        foreach ($cases as [$id, $input, $code]) {
            try {
                CallPolicy::prepare($id, $input);
                self::fail("$id should be refused");
            } catch (PolicyException $e) {
                self::assertSame($code, $e->errorCode, $id);
            }
        }
        self::assertSame('/v1/vip/email-builder/templates/vip-07', CallPolicy::prepare('customerGetVipBuilderTemplate', ['params' => ['templateId' => 'vip-07']])['path']);
    }

    public function test_paid_calls_need_consent_and_inject_price(): void
    {
        $t = FakeCustomerTransport::json(['analysisId' => 'a']);
        $client = new CustomerApiClient($t, self::KEY);
        $body = ['analysisId' => 'run-1', 'metrics' => ['sent' => 10]];
        try {
            $client->call('customerAnalyzeCampaignInsights', ['body' => $body]);
            self::fail('consent required');
        } catch (PolicyException $e) {
            self::assertSame('CONSENT_REQUIRED', $e->errorCode);
        }
        $client->call('customerAnalyzeCampaignInsights', ['body' => $body], ['paidConsent' => ['acknowledged' => true, 'expectedPriceMillicents' => 10000]]);
        $sent = json_decode((string) $t->calls[0]['body'], true);
        self::assertSame(10000, $sent['expectedPriceMillicents']);
        self::assertTrue($sent['consent']);
        try {
            $client->call('customerResolvePaidResult', ['params' => ['recoveryId' => '6f1c1c6e-8a4b-4c1e-9a7e-2b3c4d5e6f70'], 'body' => ['action' => 'resolve', 'reason' => 'r']]);
            self::fail('confirmation required');
        } catch (PolicyException $e) {
            self::assertSame('CONFIRMATION_REQUIRED', $e->errorCode);
        }
        self::assertCount(1, $t->calls);
    }

    public function test_no_retry_and_redirects_and_non_json_refused(): void
    {
        $t = new FakeCustomerTransport(static fn (): array => throw new CustomerApiException('transport', 'boom'));
        try {
            (new CustomerApiClient($t, self::KEY))->call('customerPurchaseVip', ['body' => ['expectedPriceMillicents' => 5]], ['paidConsent' => ['acknowledged' => true, 'expectedPriceMillicents' => 5]]);
            self::fail('should throw');
        } catch (CustomerApiException) {
        }
        self::assertCount(1, $t->calls);
        foreach ([['status' => 302, 'headers' => ['Location' => 'https://evil'], 'body' => ''], ['status' => 200, 'headers' => ['Content-Type' => 'text/html'], 'body' => '<p>']] as $resp) {
            try {
                (new CustomerApiClient(new FakeCustomerTransport(static fn (): array => $resp), self::KEY))->call('customerGetAccount');
                self::fail('should refuse');
            } catch (CustomerApiException) {
                self::assertTrue(true);
            }
        }
    }

    public function test_console_requires_same_session_quote_and_consumes_it(): void
    {
        $t = FakeCustomerTransport::json(['expectedPriceMillicents' => 1200]);
        $console = new ConsoleService(new CustomerApiClient($t, self::KEY), 'Test', null, null, new FileIntentStore(IntentStoreTest::dir(), true));
        $session = new ArrayConsoleSession();
        $paid = ['operationId' => 'customerPurchaseVip', 'body' => ['expectedPriceMillicents' => 1200], 'paidConsent' => ['acknowledged' => true, 'expectedPriceMillicents' => 1200]];
        [$status, $payload] = $console->handleCall($paid, $session, 1000);
        self::assertSame(428, $status, json_encode($payload));
        self::assertCount(0, $t->calls);
        [$status, $payload] = $console->handleCall(['operationId' => 'customerGetVipPlans'], $session, 1000);
        self::assertSame(200, $status);
        self::assertSame(1200, $payload['quoteRecorded']['priceMillicents']);
        [$status] = $console->handleCall($paid, $session, 1010);
        self::assertSame(200, $status);
        [$status] = $console->handleCall($paid, $session, 1020);
        self::assertSame(428, $status, 'quote must be consumed');
        self::assertCount(2, $t->calls);
        $session->put('sendrepute_console_inflight', 1100);
        $console->handleCall(['operationId' => 'customerGetVipPlans'], $session, 1100);
        [$status] = $console->handleCall($paid, $session, 1101);
        self::assertSame(409, $status, 'in-flight guard');
    }

    public function test_console_catalog_and_page_never_contain_the_key(): void
    {
        $console = new ConsoleService(new CustomerApiClient(FakeCustomerTransport::json([]), self::KEY), 'Test');
        $catalog = $console->catalog('csrf-token');
        self::assertCount(49, $catalog['operations']);
        self::assertStringNotContainsString(self::KEY, json_encode($catalog));
        [$html, $csp] = ConsoleService::renderPage('/admin/sr', 'csrf"<x>', 'Test');
        self::assertStringNotContainsString('csrf"<x>', $html);
        self::assertStringContainsString("frame-ancestors 'none'", $csp);
        self::assertStringContainsString("style-src 'unsafe-inline'", $csp, 'inline email styles render in the preview');
        self::assertMatchesRegularExpression("/script-src 'nonce-[^']+';/", $csp);
        self::assertStringContainsString("default-src 'none'", $csp);
        self::assertStringContainsString('img-src data:;', $csp);
        [$status] = $console->handleRawCall('{bad', new ArrayConsoleSession());
        self::assertSame(400, $status);
        [$status] = $console->handleCall(['operationId' => 'customerCreateHostedBuilderHandoff', 'body' => new \stdClass()], new ArrayConsoleSession());
        self::assertSame(403, $status);
    }
}
