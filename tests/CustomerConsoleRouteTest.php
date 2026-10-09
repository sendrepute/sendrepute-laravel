<?php

declare(strict_types=1);

namespace SendRepute\Laravel\Tests;

use SendRepute\Laravel\CustomerApi\CustomerApiClient;
use SendRepute\Laravel\SendReputeServiceProvider;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Gate;
use Orchestra\Testbench\TestCase;

final class CustomerConsoleRouteTest extends TestCase
{
    private const KEY = 'sr_live_test_key_0123456789abcdef';
    private ?FakeCustomerTransport $transport = null;

    protected function getPackageProviders($app): array
    {
        return [SendReputeServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $app['config']->set('sendrepute.customer_api.api_key', self::KEY);
        $app['config']->set('sendrepute.customer_api.console.enabled', !str_contains($this->name(), 'disabled'));
        $app['config']->set('sendrepute.customer_api.console.middleware', ['web', 'auth', 'can:sendrepute-customer-api']);
        $this->transport = FakeCustomerTransport::json(['id' => 'acct']);
        $app->singleton(CustomerApiClient::class, fn () => new CustomerApiClient($this->transport, self::KEY));
    }

    private function operator(): User
    {
        $user = new User();
        $user->forceFill(['id' => 7]);

        return $user;
    }

    public function test_console_disabled_by_default_registers_no_routes(): void
    {
        $this->get('/sendrepute/customer-api')->assertNotFound();
    }

    public function test_guests_and_users_without_gate_are_refused(): void
    {
        self::assertNotSame(200, $this->get('/sendrepute/customer-api')->status());
        $this->actingAs($this->operator())->get('/sendrepute/customer-api')->assertForbidden();
    }

    public function test_authorized_operator_gets_page_catalog_and_guarded_calls(): void
    {
        Gate::define('sendrepute-customer-api', static fn (): bool => true);
        $page = $this->actingAs($this->operator())->get('/sendrepute/customer-api');
        $page->assertOk();
        self::assertStringNotContainsString(self::KEY, (string) $page->getContent());
        self::assertStringContainsString("frame-ancestors 'none'", (string) $page->headers->get('Content-Security-Policy'));
        $token = session()->token();
        $catalog = $this->get('/sendrepute/customer-api/catalog')->assertOk()->json();
        self::assertCount(49, $catalog['operations']);

        $headers = ['Origin' => 'http://localhost', 'X-CSRF-Token' => $token, 'Content-Type' => 'application/json'];
        $call = fn (array $h, string $body) => $this->call('POST', '/sendrepute/customer-api/call', [], [], [], $this->transformHeadersToServerVars($h), $body);

        $call(['Origin' => 'https://evil.example'] + $headers, '{"operationId":"customerGetAccount"}')->assertForbidden();
        $call(['X-CSRF-Token' => 'wrong'] + $headers, '{"operationId":"customerGetAccount"}')->assertForbidden();
        self::assertCount(0, $this->transport->calls);
        $ok = $call($headers, '{"operationId":"customerGetAccount","params":{},"query":{}}');
        $ok->assertOk();
        self::assertSame('acct', $ok->json('upstream.data.id'));
        $paid = $call($headers, '{"operationId":"customerPurchaseVip","body":{"expectedPriceMillicents":5},"paidConsent":{"acknowledged":true,"expectedPriceMillicents":5}}');
        $paid->assertStatus(428);
        self::assertCount(1, $this->transport->calls);
    }
}
