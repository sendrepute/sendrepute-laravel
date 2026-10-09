<?php

declare(strict_types=1);

namespace SendRepute\Laravel\CustomerApi;

/**
 * Durable intent ledger for paid and billing operations.
 *
 * Identity: sha256 of credential fingerprint, operation, method, path and the
 * canonical request body. An intent is written as "pending" under an atomic
 * cross-worker lock BEFORE the outbound request, then moved to "completed",
 * "failed" (definitive refusal) or "ambiguous" (transport error, timeout, 5xx).
 * Pending, ambiguous and completed intents block an identical request from any
 * session, worker or restart until an operator deliberately releases them.
 */
abstract class IntentLedger
{
    /**
     * Run $fn(?array $current): array{0: array|null|false, 1: mixed} atomically.
     * Return false (or null) as the first element to leave the record unchanged.
     */
    abstract protected function atomic(string $key, callable $fn): mixed;

    /** @return list<array> */
    abstract protected function all(): array;

    public static function canonicalJson(mixed $value): string
    {
        if (is_array($value)) {
            if (array_is_list($value)) {
                return '['.implode(',', array_map([self::class, 'canonicalJson'], $value)).']';
            }
            ksort($value, SORT_STRING);
            $parts = [];
            foreach ($value as $k => $v) {
                $parts[] = json_encode((string) $k, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).':'.self::canonicalJson($v);
            }

            return '{'.implode(',', $parts).'}';
        }

        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public static function key(string $fingerprint, array $prepared): string
    {
        $body = $prepared['body'] === null ? '' : self::canonicalJson(json_decode($prepared['body'], true, 128, JSON_THROW_ON_ERROR));

        return hash('sha256', implode("\n", ['sendrepute-intent-v1', $fingerprint, $prepared['op']['id'], $prepared['method'], $prepared['path'], $body]));
    }

    public static function replayField(array $op): ?string
    {
        return in_array('recoveryId', $op['bodyFields'], true) ? 'recoveryId' : (in_array('analysisId', $op['bodyFields'], true) ? 'analysisId' : null);
    }

    public static function newReplayId(?string $field): ?string
    {
        if ($field === 'recoveryId') {
            $b = random_bytes(16);
            $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
            $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
            $h = bin2hex($b);

            return substr($h, 0, 8).'-'.substr($h, 8, 4).'-'.substr($h, 12, 4).'-'.substr($h, 16, 4).'-'.substr($h, 20);
        }

        return $field === 'analysisId' ? 'sr_'.bin2hex(random_bytes(16)) : null;
    }

    /** @return array{acquired:bool, record:array} */
    public function begin(string $key, array $meta, int $now): array
    {
        self::checkKey($key);

        return $this->atomic($key, static function (?array $rec) use ($key, $meta, $now): array {
            if ($rec !== null && in_array($rec['state'], ['pending', 'ambiguous', 'completed'], true)) {
                return [false, ['acquired' => false, 'record' => $rec]];
            }
            $next = [
                'key' => $key, 'fingerprint' => $meta['fingerprint'], 'operationId' => $meta['operationId'], 'state' => 'pending',
                'replayField' => $meta['replayField'] ?? null, 'replayId' => ($rec['replayId'] ?? null) ?: ($meta['replayId'] ?? null),
                'createdAt' => $rec['createdAt'] ?? $now, 'updatedAt' => $now, 'attempts' => ($rec['attempts'] ?? 0) + 1, 'httpStatus' => null, 'note' => null,
            ];

            return [$next, ['acquired' => true, 'record' => $next]];
        });
    }

    public function finish(string $key, string $state, ?int $httpStatus, int $now): ?array
    {
        self::checkKey($key);

        return $this->atomic($key, static function (?array $rec) use ($state, $httpStatus, $now): array {
            if ($rec === null || $rec['state'] !== 'pending') {
                return [false, $rec];
            }
            $next = ['state' => $state, 'httpStatus' => $httpStatus, 'updatedAt' => $now] + $rec;

            return [$next, $next];
        });
    }

    public function release(string $key, string $fingerprint, string $reason, int $now): array
    {
        self::checkKey($key);

        return $this->atomic($key, static function (?array $rec) use ($fingerprint, $reason, $now): array {
            if ($rec === null || !hash_equals((string) $rec['fingerprint'], $fingerprint)) {
                throw new IntentConflict(404, 'INTENT_NOT_FOUND', 'No intent with that key for this credential');
            }
            // Never time-based: a pending intent may belong to a stalled worker whose request is still in flight.
            if ($rec['state'] === 'pending') {
                throw new IntentConflict(409, 'INTENT_IN_PROGRESS', 'This request may still be in flight. If its worker crashed, stop all workers and run offline recovery; it then becomes ambiguous and can be reconciled and released.', $rec);
            }
            if (in_array($rec['state'], ['released', 'failed'], true)) {
                return [false, $rec];
            }
            // A completed charge must not reuse its replay id, otherwise upstream would replay the old result.
            $next = ['state' => 'released', 'replayId' => $rec['state'] === 'completed' ? null : $rec['replayId'], 'updatedAt' => $now, 'note' => mb_substr($reason, 0, 200)] + $rec;

            return [$next, $next];
        });
    }

    /**
     * OFFLINE ONLY (every worker stopped): no request can still be in flight, so
     * leftover pending intents become ambiguous for operator reconciliation.
     *
     * @return list<string> keys changed
     */
    public function recoverPendingAfterShutdown(bool $allWorkersStopped, int $now): array
    {
        if (!$allWorkersStopped) {
            throw new CustomerApiException('configuration', 'Stop every console worker first, then pass allWorkersStopped: true.');
        }
        $changed = [];
        foreach ($this->all() as $r) {
            if (($r['state'] ?? null) !== 'pending') {
                continue;
            }
            $key = $this->atomic((string) $r['key'], static fn (?array $cur): array => $cur !== null && $cur['state'] === 'pending'
                ? [['state' => 'ambiguous', 'updatedAt' => $now, 'note' => 'recovered offline after all workers stopped'] + $cur, $cur['key']]
                : [false, null]);
            if ($key !== null) {
                $changed[] = $key;
            }
        }

        return $changed;
    }

    /** @return list<array> */
    public function list(string $fingerprint): array
    {
        $rows = array_values(array_filter($this->all(), static fn (array $r): bool => hash_equals((string) ($r['fingerprint'] ?? ''), $fingerprint) && $r['state'] !== 'released'));
        usort($rows, static fn (array $a, array $b): int => $b['updatedAt'] <=> $a['updatedAt']);

        return array_slice($rows, 0, 200);
    }

    protected static function checkKey(string $key): void
    {
        if (preg_match('/\A[a-f0-9]{64}\z/', $key) !== 1) {
            throw new IntentConflict(400, 'INVALID_PARAMETER', 'intentKey is invalid');
        }
    }
}
