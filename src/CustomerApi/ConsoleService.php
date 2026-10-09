<?php

declare(strict_types=1);

namespace SendRepute\Laravel\CustomerApi;

/**
 * Framework-neutral operator console logic. The framework adapter must have
 * already authenticated and authorized the operator and verified CSRF before
 * calling handleCall(). Paid operations require a recent quote recorded in this
 * session, explicit price consent and run at most once per quote.
 */
final class ConsoleService
{
    private const QUOTES = 'sendrepute_console_quotes';
    private const INFLIGHT = 'sendrepute_console_inflight';
    private const PRICING = 'sendrepute_console_pricing';

    /** @param list<string>|null $enabledOperations */
    public function __construct(
        private readonly CustomerApiClient $client,
        private readonly string $product,
        private readonly ?string $handoffReturnOrigin = null,
        private readonly ?array $enabledOperations = null,
        private readonly ?IntentLedger $intents = null,
    ) {
        if ($handoffReturnOrigin !== null && preg_match('#\Ahttps://[a-z0-9.-]{1,253}(:\d{1,5})?\z#', $handoffReturnOrigin) !== 1) {
            throw new CustomerApiException('configuration', 'handoff_return_origin must be an exact https origin.');
        }
    }

    /** @return list<string> */
    public function enabled(): array
    {
        $ids = $this->enabledOperations ?? array_map(static fn (array $o): string => $o['id'], OperationCatalog::operations());
        if ($this->handoffReturnOrigin === null) {
            $ids = array_values(array_filter($ids, static fn (string $id): bool => $id !== 'customerCreateHostedBuilderHandoff'));
        }

        return $ids;
    }

    public function catalog(string $csrf): array
    {
        $enabled = $this->enabled();
        $ops = [];
        foreach (OperationCatalog::operations() as $op) {
            $on = in_array($op['id'], $enabled, true);
            $op['enabled'] = $on;
            if (!$on) {
                $op['disabledReason'] = $op['handoff'] && $this->handoffReturnOrigin === null
                    ? 'Configure the handoff return origin to enable hosted builder handoffs.'
                    : 'Disabled by server configuration.';
            }
            $ops[] = $op;
        }

        return ['product' => $this->product, 'apiVersion' => OperationCatalog::apiVersion(), 'csrf' => $csrf, 'operations' => $ops];
    }

    /** Decode the raw browser JSON (bounded) and run handleCall(). */
    public function handleRawCall(string $raw, ConsoleSession $session, ?int $now = null): array
    {
        if (strlen($raw) > 655360) {
            return [413, ['error' => ['code' => 'BODY_TOO_LARGE', 'message' => 'Console request too large']]];
        }
        try {
            $decoded = json_decode($raw, false, 64, JSON_THROW_ON_ERROR);
            $payload = CallPolicy::normalize($decoded);
        } catch (\JsonException) {
            return [400, ['error' => ['code' => 'INVALID_JSON', 'message' => 'Console request is not valid JSON']]];
        } catch (PolicyException $e) {
            return [$e->status, ['error' => ['code' => $e->errorCode, 'message' => $e->getMessage()]]];
        }

        return $this->handleCall($payload, $session, $now);
    }

    /** @return array{0:int,1:array} HTTP status and JSON payload for the browser */
    public function handleCall(mixed $payload, ConsoleSession $session, ?int $now = null): array
    {
        $now ??= time();
        try {
            if (!is_array($payload) || !is_string($payload['operationId'] ?? null)) {
                throw new PolicyException(400, 'INVALID_JSON', 'Console request must name an operation');
            }
            if ($payload['operationId'] === 'sendrepute.listIntents') {
                return [200, ['intents' => array_map([self::class, 'publicIntent'], $this->store()->list($this->client->credentialFingerprint()))]];
            }
            if ($payload['operationId'] === 'sendrepute.releaseIntent') {
                $store = $this->store();
                if (($payload['confirm'] ?? null) !== true) {
                    throw new PolicyException(428, 'CONFIRMATION_REQUIRED', 'Releasing an intent requires explicit confirmation');
                }
                $reason = $payload['reason'] ?? null;
                if (!is_string($reason) || strlen(trim($reason)) < 3 || strlen($reason) > 200) {
                    throw new PolicyException(400, 'INVALID_PARAMETER', 'Give a reason of 3-200 characters');
                }

                return [200, ['intent' => self::publicIntent($store->release((string) ($payload['intentKey'] ?? ''), $this->client->credentialFingerprint(), trim($reason), $now))]];
            }
            $quotes = $session->get(self::QUOTES, []);
            $quotes = is_array($quotes) ? $quotes : [];
            $op = OperationCatalog::get($payload['operationId']);
            $classifyOptions = $payload['operationId'] === CallPolicy::CLASSIFY_OPERATION ? ['expectedPricing' => $session->get(self::PRICING)] : [];
            $prepared = CallPolicy::prepare($payload['operationId'], [
                'params' => $payload['params'] ?? [],
                'query' => $payload['query'] ?? [],
                'body' => $payload['body'] ?? null,
            ], $classifyOptions + [
                'enabled' => $this->enabled(),
                'paidConsent' => $payload['paidConsent'] ?? null,
                'confirm' => $payload['confirm'] ?? null,
                'requireQuote' => true,
                'quotedAt' => $op !== null && $op['quote'] !== null ? ($quotes[$op['quote']] ?? null) : null,
                'now' => $now,
                'handoffReturnOrigin' => $this->handoffReturnOrigin,
            ]);
            if (!$prepared['op']['paid'] && !$prepared['op']['financial']) {
                $upstream = $this->client->send($prepared);
                $result = ['upstream' => $upstream];
                if ($upstream['ok'] && OperationCatalog::isQuoteOperation($prepared['op']['id'])) {
                    $quotes[$prepared['op']['id']] = $now;
                    $session->put(self::QUOTES, $quotes);
                    $result['quoteRecorded'] = ['priceMillicents' => CallPolicy::extractQuotedPrice($upstream['data'])];
                    if ($prepared['op']['id'] === 'customerGetPricingSettings') {
                        // Server-returned rate schedule; classification consent must match it exactly.
                        $rates = CallPolicy::classificationRates($upstream['data']);
                        $session->put(self::PRICING, $rates);
                        $result['quoteRecorded']['pricing'] = $rates;
                    }
                }

                return [200, $result];
            }

            $store = $this->store();
            $inflight = $session->get(self::INFLIGHT);
            if (is_int($inflight) && $now - $inflight < 180) {
                throw new PolicyException(409, 'PAID_IN_FLIGHT', 'Another paid or billing operation is still running in this session');
            }
            $fingerprint = $this->client->credentialFingerprint();
            $key = IntentLedger::key($fingerprint, $prepared);
            $field = IntentLedger::replayField($prepared['op']);
            $bodyObj = $prepared['body'] !== null ? json_decode($prepared['body'], false, 128, JSON_THROW_ON_ERROR) : null;
            $supplied = $field !== null && $bodyObj instanceof \stdClass && is_string($bodyObj->{$field} ?? null) && $bodyObj->{$field} !== '' ? $bodyObj->{$field} : null;
            // Persist the intent and upstream replay identity BEFORE anything is sent.
            $begun = $store->begin($key, ['fingerprint' => $fingerprint, 'operationId' => $prepared['op']['id'], 'replayField' => $field, 'replayId' => $supplied ?? IntentLedger::newReplayId($field)], $now);
            if (!$begun['acquired']) {
                $rec = $begun['record'];
                $message = match ($rec['state']) {
                    'completed' => 'An identical paid request already completed. Release it deliberately to run a second, separate charge.',
                    'ambiguous' => 'An identical paid request has an unknown outcome. Reconcile it (ledger or paid-result recovery) and release it before resending.',
                    default => 'An identical paid request is in progress in another session or worker.',
                };
                throw new IntentConflict(409, $rec['state'] === 'completed' ? 'INTENT_COMPLETED' : 'INTENT_LOCKED', $message, $rec);
            }
            if ($field !== null && $supplied === null && $begun['record']['replayId'] !== null && $bodyObj instanceof \stdClass) {
                $bodyObj->{$field} = $begun['record']['replayId'];
                $prepared['body'] = json_encode($bodyObj, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            }
            if ($prepared['op']['paid']) {
                unset($quotes[$prepared['op']['quote']]);
                $session->put(self::QUOTES, $quotes);
            }
            if ($prepared['op']['id'] === CallPolicy::CLASSIFY_OPERATION) {
                $session->put(self::PRICING, null);
            }
            $session->put(self::INFLIGHT, $now);
            try {
                $upstream = $this->client->send($prepared);
            } catch (CustomerApiException $e) {
                $rec = $this->safeFinish($store, $key, 'ambiguous', null, $now) ?? $begun['record'];
                throw new IntentConflict(502, 'UPSTREAM_AMBIGUOUS', 'Outcome unknown ('.$e->errorCode.'). Nothing was retried; the intent stays locked until reconciled.', $rec);
            } finally {
                $session->put(self::INFLIGHT, null);
            }
            $status = $upstream['status'];
            $state = $upstream['ok'] ? 'completed' : (($status >= 400 && $status < 500 && $status !== 408 && $status !== 425) ? 'failed' : 'ambiguous');
            $rec = $this->safeFinish($store, $key, $state, $status, $now) ?? ['state' => 'pending'] + $begun['record'];
            $result = ['upstream' => $upstream, 'intent' => self::publicIntent($rec)];
            $charged = self::findInt($upstream['data'], 'chargedMillicents');
            $consented = $payload['paidConsent']['maxChargeMillicents'] ?? $payload['paidConsent']['expectedPriceMillicents'] ?? null;
            if ($charged !== null && is_int($consented) && $charged > $consented) {
                $result['priceAlert'] = ['chargedMillicents' => $charged, 'consentedMillicents' => $consented];
            }

            return [200, $result];
        } catch (IntentConflict $e) {
            return [$e->status, ['error' => ['code' => $e->errorCode, 'message' => $e->getMessage(), 'intent' => self::publicIntent($e->record)]]];
        } catch (PolicyException $e) {
            return [$e->status, ['error' => ['code' => $e->errorCode, 'message' => $e->getMessage()]]];
        } catch (CustomerApiException $e) {
            return [502, ['error' => ['code' => strtoupper($e->errorCode), 'message' => $e->getMessage()]]];
        } catch (\JsonException) {
            return [400, ['error' => ['code' => 'INVALID_JSON', 'message' => 'Request body could not be encoded']]];
        }
    }

    private function store(): IntentLedger
    {
        if ($this->intents === null) {
            throw new PolicyException(503, 'INTENT_STORE_REQUIRED', 'Paid and billing operations are disabled until a durable intent store is configured');
        }

        return $this->intents;
    }

    private function safeFinish(IntentLedger $store, string $key, string $state, ?int $status, int $now): ?array
    {
        try {
            return $store->finish($key, $state, $status, $now);
        } catch (\Throwable) {
            return null;
        }
    }

    public static function publicIntent(?array $r): ?array
    {
        if ($r === null) {
            return null;
        }
        unset($r['fingerprint']);

        return $r;
    }

    private static function findInt(mixed $data, string $name, int $depth = 0): ?int
    {
        if (!is_array($data) || $depth > 4) {
            return null;
        }
        if (isset($data[$name]) && is_int($data[$name])) {
            return $data[$name];
        }
        foreach ($data as $v) {
            if (($f = self::findInt($v, $name, $depth + 1)) !== null) {
                return $f;
            }
        }

        return null;
    }

    /** @return array{0:string,1:string} rendered HTML and the Content-Security-Policy header value */
    public static function renderPage(string $basePath, string $csrf, string $product): array
    {
        $nonce = base64_encode(random_bytes(18));
        $esc = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $html = (string) file_get_contents(__DIR__.'/resources/admin-ui.html');
        $html = str_replace('__SR_NONCE__', $nonce, $html);
        $html = str_replace(['__SR_BASE__', '__SR_CSRF__', '__SR_LOGIN__', '__SR_PRODUCT__'], [$esc($basePath), $esc($csrf), '0', $esc($product)], $html);
        $csp = "default-src 'none'; script-src 'nonce-{$nonce}'; style-src 'unsafe-inline'; connect-src 'self'; img-src data:; frame-src 'self'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'";

        return [$html, $csp];
    }

    public static function securityHeaders(): array
    {
        return ['Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff', 'Referrer-Policy' => 'no-referrer', 'X-Frame-Options' => 'DENY'];
    }
}
