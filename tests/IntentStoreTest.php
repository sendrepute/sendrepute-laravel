<?php

declare(strict_types=1);

namespace SendRepute\Laravel\Tests;

require_once __DIR__.'/CustomerApiTest.php';

use SendRepute\Laravel\CustomerApi\ConsoleService;
use SendRepute\Laravel\CustomerApi\CustomerApiClient;
use SendRepute\Laravel\CustomerApi\CustomerApiException;
use SendRepute\Laravel\CustomerApi\FileIntentStore;
use SendRepute\Laravel\CustomerApi\LaravelCacheIntentStore;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\FileStore;
use Illuminate\Cache\Repository;
use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\TestCase;

final class IntentStoreTest extends TestCase
{
    private const KEY = 'sr_live_test_key_0123456789abcdef';
    private const ACCESS = ['operationId' => 'customerCreateVipEmailBuilderAccess', 'body' => ['designId' => 'design-1', 'sourceKind' => 'template', 'templateId' => 'vip-01'], 'paidConsent' => ['acknowledged' => true, 'expectedPriceMillicents' => 2500]];
    private const PRICING = ['operationId' => 'customerGetPricingSettings'];

    public static function dir(): string
    {
        $dir = sys_get_temp_dir().'/sr-intents-'.bin2hex(random_bytes(6));
        mkdir($dir, 0700);

        return $dir;
    }

    public function test_paid_calls_are_refused_without_an_intent_store(): void
    {
        $t = FakeCustomerTransport::json(['expectedPriceMillicents' => 2500]);
        $console = new ConsoleService(new CustomerApiClient($t, self::KEY), 'Test');
        $s = new ArrayConsoleSession();
        $console->handleCall(self::PRICING, $s, 1000);
        [$status, $payload] = $console->handleCall(self::ACCESS, $s, 1001);
        self::assertSame(503, $status);
        self::assertSame('INTENT_STORE_REQUIRED', $payload['error']['code']);
        self::assertCount(1, $t->calls);
    }

    public function test_ambiguous_failure_stays_locked_across_sessions_and_restart_and_release_keeps_replay_id(): void
    {
        $dir = self::dir();
        $mode = 'fail';
        $t = new FakeCustomerTransport(static function () use (&$mode, &$t): array {
            $last = end($t->calls);
            if (str_ends_with($last['url'], '/v1/pricing')) {
                return ['status' => 200, 'headers' => ['Content-Type' => 'application/json'], 'body' => '{"expectedPriceMillicents":2500}'];
            }
            if ($mode === 'fail') {
                throw new CustomerApiException('transport', 'connection reset');
            }

            return ['status' => 201, 'headers' => ['Content-Type' => 'application/json'], 'body' => '{"accessId":"a","billing":{"chargedMillicents":2500}}'];
        });
        $paidBodies = static fn (): array => array_values(array_map(static fn ($c) => json_decode((string) $c['body'], true), array_filter($t->calls, static fn ($c) => str_contains($c['url'], '/v1/vip/email-builder/access'))));
        $console = new ConsoleService(new CustomerApiClient($t, self::KEY), 'Test', null, null, new FileIntentStore($dir, true));
        $a = new ArrayConsoleSession();
        $b = new ArrayConsoleSession();
        $console->handleCall(self::PRICING, $a, 1000);
        [$status, $payload] = $console->handleCall(self::ACCESS, $a, 1001);
        self::assertSame(502, $status);
        self::assertSame('UPSTREAM_AMBIGUOUS', $payload['error']['code']);
        $replay = $payload['error']['intent']['replayId'];
        self::assertMatchesRegularExpression('/\A[0-9a-f]{8}-[0-9a-f]{4}-4/', $replay);
        self::assertSame($replay, $paidBodies()[0]['recoveryId'], 'replay identity persisted before sending');
        self::assertArrayNotHasKey('fingerprint', $payload['error']['intent']);
        $console->handleCall(self::PRICING, $b, 1002);
        [$status, $payload] = $console->handleCall(self::ACCESS, $b, 1003);
        self::assertSame(409, $status);
        self::assertSame('INTENT_LOCKED', $payload['error']['code']);

        // Restart: new service and store instance on the same directory.
        $console = new ConsoleService(new CustomerApiClient($t, self::KEY), 'Test', null, null, new FileIntentStore($dir, true));
        $c = new ArrayConsoleSession();
        $console->handleCall(self::PRICING, $c, 2000);
        [, $payload] = $console->handleCall(self::ACCESS, $c, 2001);
        self::assertSame('INTENT_LOCKED', $payload['error']['code']);
        [, $list] = $console->handleCall(['operationId' => 'sendrepute.listIntents'], $c, 2002);
        self::assertCount(1, $list['intents']);
        $key = $list['intents'][0]['key'];
        [$status] = $console->handleCall(['operationId' => 'sendrepute.releaseIntent', 'intentKey' => $key, 'reason' => 'ledger clean'], $c, 2003);
        self::assertSame(428, $status);
        [$status, $rel] = $console->handleCall(['operationId' => 'sendrepute.releaseIntent', 'intentKey' => $key, 'reason' => 'ledger clean', 'confirm' => true], $c, 2004);
        self::assertSame('released', $rel['intent']['state']);
        $mode = 'ok';
        $console->handleCall(self::PRICING, $c, 2005);
        [$status, $done] = $console->handleCall(self::ACCESS, $c, 2006);
        self::assertSame(200, $status);
        self::assertSame('completed', $done['intent']['state']);
        self::assertSame($replay, $paidBodies()[1]['recoveryId']);
        $console->handleCall(self::PRICING, $c, 2007);
        [, $dup] = $console->handleCall(self::ACCESS, $c, 2008);
        self::assertSame('INTENT_COMPLETED', $dup['error']['code']);
        $console->handleCall(['operationId' => 'sendrepute.releaseIntent', 'intentKey' => $key, 'reason' => 'second purchase', 'confirm' => true], $c, 2009);
        $console->handleCall(self::PRICING, $c, 2010);
        [$status] = $console->handleCall(self::ACCESS, $c, 2011);
        self::assertSame(200, $status);
        self::assertNotSame($replay, $paidBodies()[2]['recoveryId']);
        self::assertCount(3, $paidBodies());
    }

    public function test_upstream_5xx_is_ambiguous_and_definitive_4xx_is_failed(): void
    {
        $status = 503;
        $t = new FakeCustomerTransport(static function () use (&$status, &$t): array {
            $last = end($t->calls);

            return str_ends_with($last['url'], '/v1/pricing')
                ? ['status' => 200, 'headers' => ['Content-Type' => 'application/json'], 'body' => '{"expectedPriceMillicents":2500}']
                : ['status' => $status, 'headers' => ['Content-Type' => 'application/json'], 'body' => '{"error":{"code":"X"}}'];
        });
        $console = new ConsoleService(new CustomerApiClient($t, self::KEY), 'Test', null, null, new FileIntentStore(self::dir(), true));
        $s = new ArrayConsoleSession();
        $console->handleCall(self::PRICING, $s, 1);
        [, $p] = $console->handleCall(self::ACCESS, $s, 2);
        self::assertSame('ambiguous', $p['intent']['state']);
        $other = self::ACCESS;
        $other['body']['designId'] = 'design-2';
        $status = 409;
        $console->handleCall(self::PRICING, $s, 3);
        [, $p] = $console->handleCall($other, $s, 4);
        self::assertSame('failed', $p['intent']['state']);
        $console->handleCall(self::PRICING, $s, 5);
        [, $p] = $console->handleCall($other, $s, 6);
        self::assertSame('failed', $p['intent']['state'], 'definitive refusal can be resent');
    }

    public function test_filesystem_store_refuses_unsupported_configuration(): void
    {
        foreach ([[self::dir(), false, 'single host'], ['relative/dir', true, 'absolute']] as [$dir, $ack, $msg]) {
            try {
                new FileIntentStore($dir, $ack);
                self::fail('should refuse');
            } catch (CustomerApiException $e) {
                self::assertStringContainsString($msg, $e->getMessage());
            }
        }
        $open = self::dir();
        chmod($open, 0755);
        $this->expectException(CustomerApiException::class);
        new FileIntentStore($open, true);
    }

    public function test_begin_is_atomic_across_processes(): void
    {
        $dir = self::dir();
        $autoload = dirname(__DIR__).'/vendor/autoload.php';
        $code = 'require '.var_export($autoload, true).'; $s = new \\SendRepute\Laravel\\CustomerApi\\FileIntentStore('.var_export($dir, true).', true);'
            .' $r = $s->begin(str_repeat("a", 64), ["fingerprint" => "f", "operationId" => "customerPurchaseVip"], 1); echo $r["acquired"] ? "1" : "0";';
        $procs = [];
        for ($i = 0; $i < 8; ++$i) {
            $procs[] = proc_open([PHP_BINARY, '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $outs[] = $pipes[1];
        }
        $wins = 0;
        foreach ($procs as $i => $p) {
            $wins += (int) (stream_get_contents($outs[$i]) === '1');
            proc_close($p);
        }
        self::assertSame(1, $wins, 'exactly one worker acquires the intent');
    }

    public function test_laravel_cache_store_is_durable_locked_and_refuses_array_driver(): void
    {
        try {
            new LaravelCacheIntentStore(new Repository(new ArrayStore()), 'array');
            self::fail('array driver must be refused');
        } catch (CustomerApiException $e) {
            self::assertStringContainsString('not durable', $e->getMessage());
        }
        $dir = self::dir();
        $key = str_repeat('b', 64);
        $first = new LaravelCacheIntentStore(new Repository(new FileStore(new Filesystem(), $dir)), 'file');
        self::assertTrue($first->begin($key, ['fingerprint' => 'f', 'operationId' => 'customerPurchaseVip'], 1)['acquired']);
        $first->finish($key, 'ambiguous', null, 2);
        // A second worker / restart with a fresh repository sees the lock.
        $second = new LaravelCacheIntentStore(new Repository(new FileStore(new Filesystem(), $dir)), 'file');
        $again = $second->begin($key, ['fingerprint' => 'f', 'operationId' => 'customerPurchaseVip'], 3);
        self::assertFalse($again['acquired']);
        self::assertSame('ambiguous', $again['record']['state']);
        self::assertCount(1, $second->list('f'));
    }
}
