<?php

declare(strict_types=1);

namespace SendRepute\Laravel\Tests;

require_once __DIR__.'/CustomerApiTest.php';
require_once __DIR__.'/IntentStoreTest.php';

use SendRepute\Laravel\CustomerApi\ConsoleService;
use SendRepute\Laravel\CustomerApi\CustomerApiClient;
use SendRepute\Laravel\CustomerApi\CustomerApiException;
use SendRepute\Laravel\CustomerApi\FileIntentStore;
use SendRepute\Laravel\CustomerApi\IntentConflict;
use PHPUnit\Framework\TestCase;

final class ClassificationPriceTest extends TestCase
{
    private const KEY = 'sr_live_test_key_0123456789abcdef';
    private const RATES = ['classificationBaseMillicents' => 300, 'includedUniqueTerms' => 5, 'additionalTermMillicents' => 40, 'maximumClassificationMillicents' => 2000];
    private const EMAIL = ['sender' => 'Ops Team', 'subject' => 'Renewal', 'body' => 'Your plan renews Friday.'];

    private static function consent(array $rates, int $max): array
    {
        return ['operationId' => 'classifyCustomerEmail', 'body' => self::EMAIL, 'paidConsent' => ['acknowledged' => true, 'expectedPricing' => $rates, 'maxChargeMillicents' => $max]];
    }

    public function test_generic_client_sends_exact_authorization_and_refuses_mismatch(): void
    {
        $t = FakeCustomerTransport::json(['requestId' => 'r']);
        $client = new CustomerApiClient($t, self::KEY);
        $client->call('classifyCustomerEmail', ['body' => self::EMAIL], ['paidConsent' => ['acknowledged' => true, 'expectedPricing' => self::RATES, 'maxChargeMillicents' => 1200]]);
        self::assertSame(['expectedPricing' => self::RATES, 'maxChargeMillicents' => 1200], json_decode((string) $t->calls[0]['body'], true)['priceAuthorization']);
        try {
            $client->call('classifyCustomerEmail', ['body' => self::EMAIL + ['priceAuthorization' => ['expectedPricing' => ['additionalTermMillicents' => 41] + self::RATES, 'maxChargeMillicents' => 1200]]], ['paidConsent' => ['acknowledged' => true, 'expectedPricing' => self::RATES, 'maxChargeMillicents' => 1200]]);
            self::fail('mismatch accepted');
        } catch (\Throwable $e) {
            self::assertStringContainsString('PRICE_MISMATCH', $e->getCode().' '.(property_exists($e, 'errorCode') ? $e->errorCode : '').' '.$e->getMessage().' '.get_class($e).(method_exists($e, 'code') ? $e->code() : ''));
        }
        self::assertCount(1, $t->calls);
    }

    public function test_console_sends_full_schedule_rejects_stale_confirmation_and_maps_price_changed(): void
    {
        $rates = self::RATES;
        $classifyStatus = 200;
        $t = new FakeCustomerTransport(static function () use (&$t, &$rates, &$classifyStatus): array {
            $last = end($t->calls);
            if (str_ends_with($last['url'], '/v1/pricing')) {
                return ['status' => 200, 'headers' => ['Content-Type' => 'application/json'], 'body' => json_encode($rates + ['maximumTermsThreshold' => 50, 'editTermMillicents' => 0])];
            }

            return $classifyStatus === 200
                ? ['status' => 200, 'headers' => ['Content-Type' => 'application/json'], 'body' => '{"requestId":"req-1","billing":{"chargedMillicents":340}}']
                : ['status' => 409, 'headers' => ['Content-Type' => 'application/json'], 'body' => '{"error":{"code":"PRICE_CHANGED"}}'];
        });
        $classifies = static fn (): array => array_values(array_map(static fn ($c) => json_decode((string) $c['body'], true), array_filter($t->calls, static fn ($c) => str_ends_with($c['url'], '/v1/classify'))));
        $console = new ConsoleService(new CustomerApiClient($t, self::KEY), 'Test', null, null, new FileIntentStore(IntentStoreTest::dir(), true));
        $s = new ArrayConsoleSession();

        self::assertSame('QUOTE_REQUIRED', $console->handleCall(self::consent(self::RATES, 1500), $s, 1000)[1]['error']['code']);
        [, $q] = $console->handleCall(['operationId' => 'customerGetPricingSettings'], $s, 1001);
        self::assertSame(self::RATES, $q['quoteRecorded']['pricing']);
        [$st, $done] = $console->handleCall(self::consent(self::RATES, 1500), $s, 1002);
        self::assertSame(200, $st);
        self::assertSame(['expectedPricing' => self::RATES, 'maxChargeMillicents' => 1500], $classifies()[0]['priceAuthorization']);
        self::assertSame('completed', $done['intent']['state']);

        $rates = ['additionalTermMillicents' => 55] + self::RATES;
        $console->handleCall(['operationId' => 'customerGetPricingSettings'], $s, 1003);
        [$st, $stale] = $console->handleCall(self::consent(self::RATES, 1600), $s, 1004);
        self::assertSame(409, $st);
        self::assertSame('PRICE_CONFIRMATION_STALE', $stale['error']['code']);
        self::assertCount(1, $classifies());
        self::assertSame(200, $console->handleCall(self::consent($rates, 1600), $s, 1005)[0]);
        self::assertEquals(['expectedPricing' => $rates, 'maxChargeMillicents' => 1600], $classifies()[1]['priceAuthorization']);

        $classifyStatus = 409;
        $console->handleCall(['operationId' => 'customerGetPricingSettings'], $s, 1006);
        [, $changed] = $console->handleCall(self::consent($rates, 1700), $s, 1007);
        self::assertSame(409, $changed['upstream']['status']);
        self::assertSame('failed', $changed['intent']['state']);
        self::assertSame('QUOTE_REQUIRED', $console->handleCall(self::consent($rates, 1700), $s, 1008)[1]['error']['code']);
        self::assertCount(3, $classifies());
    }

    public function test_pending_intent_is_never_released_by_time_only_after_offline_recovery(): void
    {
        $store = new FileIntentStore(IntentStoreTest::dir(), true);
        $key = str_repeat('a', 64);
        self::assertTrue($store->begin($key, ['fingerprint' => 'f', 'operationId' => 'customerPurchaseVip'], 1)['acquired']);
        try {
            $store->release($key, 'f', 'try', 999999);
            self::fail('pending released');
        } catch (IntentConflict $e) {
            self::assertStringContainsString('in flight', $e->getMessage());
        }
        try {
            $store->recoverPendingAfterShutdown(false, 2);
            self::fail('online recovery allowed');
        } catch (CustomerApiException) {
        }
        self::assertSame([$key], $store->recoverPendingAfterShutdown(true, 3));
        self::assertSame('released', $store->release($key, 'f', 'reconciled', 4)['state']);
    }
}
