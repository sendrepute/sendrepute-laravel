<?php

declare(strict_types=1);

namespace SendRepute\Laravel\CustomerApi;

use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;

/**
 * Intent store on a Laravel cache store with atomic locks (redis, database,
 * memcached, dynamodb, or file on a single host). The array and null drivers
 * are refused because they are not durable. Records are stored without expiry;
 * use a store that does not evict keys under memory pressure.
 */
final class LaravelCacheIntentStore extends IntentLedger
{
    private const PREFIX = 'sendrepute:intent:';

    public function __construct(private readonly Repository $cache, string $driver)
    {
        if (in_array($driver, ['array', 'null'], true)) {
            throw new CustomerApiException('configuration', 'The '.$driver.' cache driver is not durable; choose redis, database, memcached, dynamodb or a single-host file store.');
        }
        if (!$cache->getStore() instanceof LockProvider) {
            throw new CustomerApiException('configuration', 'The selected cache store does not support atomic locks.');
        }
    }

    protected function atomic(string $key, callable $fn): mixed
    {
        /** @var LockProvider $store */
        $store = $this->cache->getStore();
        $lock = $store->lock(self::PREFIX.'lock:'.$key, 30);
        try {
            $lock->block(5);
        } catch (\Throwable) {
            throw new IntentConflict(503, 'INTENT_STORE_BUSY', 'Intent store lock is busy; nothing was sent');
        }
        try {
            $current = $this->cache->get(self::PREFIX.$key);
            [$next, $result] = $fn(is_array($current) ? $current : null);
            if (is_array($next)) {
                if (!$this->cache->forever(self::PREFIX.$key, $next)) {
                    throw new IntentConflict(503, 'INTENT_STORE_UNAVAILABLE', 'Intent store write failed; nothing was sent');
                }
                $this->index($key, $store);
            }

            return $result;
        } finally {
            $lock->release();
        }
    }

    private function index(string $key, LockProvider $store): void
    {
        $lock = $store->lock(self::PREFIX.'lock:index', 30);
        $lock->block(5);
        try {
            $index = $this->cache->get(self::PREFIX.'index', []);
            $index = is_array($index) ? $index : [];
            if (!in_array($key, $index, true)) {
                $index[] = $key;
                $this->cache->forever(self::PREFIX.'index', array_slice($index, -5000));
            }
        } finally {
            $lock->release();
        }
    }

    protected function all(): array
    {
        $out = [];
        foreach ((array) $this->cache->get(self::PREFIX.'index', []) as $key) {
            $row = is_string($key) ? $this->cache->get(self::PREFIX.$key) : null;
            if (is_array($row)) {
                $out[] = $row;
            }
        }

        return $out;
    }
}
