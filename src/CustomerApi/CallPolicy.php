<?php

declare(strict_types=1);

namespace SendRepute\Laravel\CustomerApi;

/**
 * Validates calls against the generated catalog: allowlisted parameters and
 * body fields, 512 KiB body limit, explicit price consent for paid work,
 * confirmation for billing/recovery actions and a fixed handoff origin.
 */
final class CallPolicy
{
    public const MAX_BODY_BYTES = 524288;
    public const QUOTE_TTL_SECONDS = 900;
    private const PRICE_KEYS = ['expectedPriceMillicents', 'priceMillicents', 'quotedPriceMillicents', 'totalPriceMillicents', 'chargeMillicents', 'maximumChargeMillicents', 'amountMillicents'];

    /**
     * @param array{params?:mixed,query?:mixed,body?:mixed} $input
     * @param array{paidConsent?:mixed,confirm?:mixed,requireQuote?:bool,quotedAt?:?int,now?:int,handoffReturnOrigin?:?string,enabled?:?array} $options
     * @return array{op:array,method:string,path:string,body:?string}
     */
    public static function prepare(string $operationId, array $input = [], array $options = []): array
    {
        $op = OperationCatalog::get($operationId);
        if ($op === null) {
            throw new PolicyException(404, 'UNKNOWN_OPERATION', 'Operation is not part of the customer API catalog');
        }
        if (isset($options['enabled']) && is_array($options['enabled']) && !in_array($op['id'], $options['enabled'], true)) {
            throw new PolicyException(403, 'OPERATION_DISABLED', 'Operation is disabled on this server');
        }
        $params = $input['params'] ?? [];
        $query = $input['query'] ?? [];
        $params = $params instanceof \stdClass ? [] : $params;
        $query = $query instanceof \stdClass ? [] : $query;
        if (!self::isMap($params) || !self::isMap($query)) {
            throw new PolicyException(400, 'INVALID_INPUT', 'params and query must be objects');
        }
        $defs = $op['params'] ?? [];
        foreach (array_keys($params) as $key) {
            if (!self::hasDef($defs, 'path', (string) $key)) {
                throw new PolicyException(400, 'UNKNOWN_PARAMETER', 'Unknown path parameter '.$key);
            }
        }
        foreach (array_keys($query) as $key) {
            if (!self::hasDef($defs, 'query', (string) $key)) {
                throw new PolicyException(400, 'UNKNOWN_PARAMETER', 'Unknown query parameter '.$key);
            }
        }
        $path = $op['path'];
        $search = [];
        foreach ($defs as $def) {
            $source = $def['in'] === 'path' ? $params : $query;
            $value = $source[$def['name']] ?? null;
            if ($value === null || $value === '') {
                if ($def['in'] === 'path') {
                    throw new PolicyException(400, 'MISSING_PARAMETER', $def['name'].' is required');
                }
                continue;
            }
            $value = self::checkParam($def, $value);
            if ($def['in'] === 'path') {
                $path = str_replace('{'.$def['name'].'}', rawurlencode($value), $path);
            } else {
                $search[$def['name']] = $value;
            }
        }

        $body = null;
        if ($op['hasBody']) {
            $body = $input['body'] ?? [];
            if ($body instanceof \stdClass && get_object_vars($body) === []) {
                $body = [];
            }
            if (!self::isMap($body)) {
                throw new PolicyException(400, 'INVALID_BODY', 'Request body must be a JSON object');
            }
            foreach (array_keys($body) as $key) {
                if (!in_array($key, $op['bodyFields'], true)) {
                    throw new PolicyException(400, 'UNKNOWN_FIELD', 'Unsupported body field '.$key);
                }
            }
            if ($op['id'] === 'customerCreateVipEmailTemplate' && is_array($body['imageUrls'] ?? null) && $body['imageUrls'] !== []) {
                throw new PolicyException(400, 'URLS_REFUSED', 'imageUrls are refused by this integration; arbitrary URLs are not forwarded');
            }
            if ($op['handoff']) {
                $origin = $options['handoffReturnOrigin'] ?? null;
                if (!is_string($origin) || $origin === '') {
                    throw new PolicyException(403, 'HANDOFF_NOT_CONFIGURED', 'Hosted builder handoff requires a configured return origin');
                }
                if (isset($body['returnOrigin']) && $body['returnOrigin'] !== '' && $body['returnOrigin'] !== $origin) {
                    throw new PolicyException(400, 'RETURN_ORIGIN_FIXED', 'returnOrigin is fixed by server configuration');
                }
                $body['returnOrigin'] = $origin;
            }
        } elseif (array_key_exists('body', $input) && $input['body'] !== null) {
            throw new PolicyException(400, 'UNEXPECTED_BODY', 'This operation does not accept a request body');
        }

        if ($op['paid']) {
            $consent = $options['paidConsent'] ?? null;
            $classification = $op['id'] === self::CLASSIFY_OPERATION;
            if (!is_array($consent) || ($consent['acknowledged'] ?? null) !== true) {
                throw new PolicyException(428, 'CONSENT_REQUIRED', 'Paid operation requires explicit consent to an expected price in millicents');
            }
            $auth = $classification ? self::classificationAuthorization($consent, $body) : null;
            if (!$classification && (!is_int($consent['expectedPriceMillicents'] ?? null) || $consent['expectedPriceMillicents'] < 0)) {
                throw new PolicyException(428, 'CONSENT_REQUIRED', 'Paid operation requires explicit consent to an expected price in millicents');
            }
            $price = $consent['expectedPriceMillicents'] ?? null;
            if (($options['requireQuote'] ?? false) === true) {
                $quotedAt = $options['quotedAt'] ?? null;
                $now = $options['now'] ?? time();
                if (!is_int($quotedAt) || $now - $quotedAt > self::QUOTE_TTL_SECONDS) {
                    throw new PolicyException(428, 'QUOTE_REQUIRED', 'Run '.$op['quote'].' in this session within 15 minutes before this paid operation');
                }
            }
            if ($classification) {
                // Console: the confirmed schedule must equal the rates the server returned to this session's latest quote.
                if (array_key_exists('expectedPricing', $options) && !self::sameRates($options['expectedPricing'], $auth['expectedPricing'])) {
                    throw new PolicyException(409, 'PRICE_CONFIRMATION_STALE', 'The confirmed rates no longer match the latest GET /v1/pricing result in this session. Load current rates and confirm again.');
                }
                // The server re-checks the schedule and ceiling atomically at settlement.
                $body['priceAuthorization'] = $auth;
            } elseif ($op['priceField'] !== null) {
                $parts = explode('.', $op['priceField']);
                $ref = &$body;
                foreach (array_slice($parts, 0, -1) as $part) {
                    if (!isset($ref[$part]) || !self::isMap($ref[$part])) {
                        throw new PolicyException(400, 'PRICE_AUTHORIZATION_REQUIRED', implode('.', array_slice($parts, 0, -1)).' must be supplied for this paid operation');
                    }
                    if ($ref[$part] instanceof \stdClass) {
                        $ref[$part] = [];
                    }
                    $ref = &$ref[$part];
                }
                $leaf = end($parts);
                $current = $ref[$leaf] ?? null;
                if ($current === null || $current === '') {
                    $ref[$leaf] = $price;
                } elseif ($current !== $price) {
                    throw new PolicyException(409, 'PRICE_MISMATCH', $op['priceField'].' differs from the consented price');
                }
                unset($ref);
            }
            if ($op['consentFlag'] !== null) {
                $body[$op['consentFlag']] = true;
            }
        }
        if ($op['financial'] && ($options['confirm'] ?? null) !== true) {
            throw new PolicyException(428, 'CONFIRMATION_REQUIRED', 'This billing or recovery action requires explicit confirmation');
        }

        $serialized = null;
        if ($op['hasBody']) {
            $serialized = $body === [] ? '{}' : json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            if (strlen($serialized) > self::MAX_BODY_BYTES) {
                throw new PolicyException(413, 'BODY_TOO_LARGE', 'Request body exceeds 512 KiB');
            }
        }
        $qs = http_build_query($search, '', '&', PHP_QUERY_RFC3986);

        return ['op' => $op, 'method' => $op['method'], 'path' => $path.($qs !== '' ? '?'.$qs : ''), 'body' => $serialized];
    }

    public static function extractQuotedPrice(mixed $data, int $depth = 0): ?int
    {
        if (!is_array($data) || $depth > 4) {
            return null;
        }
        foreach (self::PRICE_KEYS as $key) {
            if (isset($data[$key]) && is_int($data[$key])) {
                return $data[$key];
            }
        }
        foreach ($data as $value) {
            $found = self::extractQuotedPrice($value, $depth + 1);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    public const CLASSIFY_OPERATION = 'classifyCustomerEmail';
    public const CLASSIFICATION_RATE_FIELDS = ['classificationBaseMillicents', 'includedUniqueTerms', 'additionalTermMillicents', 'maximumClassificationMillicents'];

    /** The four effective classification rates from a GET /v1/pricing response, or null. */
    public static function classificationRates(mixed $data): ?array
    {
        if (!is_array($data)) {
            return null;
        }
        $out = [];
        foreach (self::CLASSIFICATION_RATE_FIELDS as $f) {
            if (!is_int($data[$f] ?? null) || $data[$f] < 0) {
                return null;
            }
            $out[$f] = $data[$f];
        }

        return $out;
    }

    private static function sameRates(mixed $a, mixed $b): bool
    {
        if (!is_array($a) || !is_array($b)) {
            return false;
        }
        foreach (self::CLASSIFICATION_RATE_FIELDS as $f) {
            if (($a[$f] ?? null) !== ($b[$f] ?? null)) {
                return false;
            }
        }

        return true;
    }

    private static function strictAuthorization(mixed $v): bool
    {
        return is_array($v) && count($v) === 2 && is_array($v['expectedPricing'] ?? null) && count($v['expectedPricing']) === 4
            && self::classificationRates($v['expectedPricing']) !== null && is_int($v['maxChargeMillicents'] ?? null) && $v['maxChargeMillicents'] >= 0;
    }

    /**
     * Exact CustomerClassificationPriceAuthorization: full effective rate schedule
     * plus a per-request ceiling. Consent is {expectedPricing, maxChargeMillicents}
     * or (legacy) a complete body priceAuthorization whose ceiling equals
     * expectedPriceMillicents.
     */
    private static function classificationAuthorization(array $consent, array $body): array
    {
        $supplied = $body['priceAuthorization'] ?? null;
        $missing = new PolicyException(428, 'CONSENT_REQUIRED', 'Classification consent needs the four effective rates from GET /v1/pricing and a maximum charge in millicents');
        if (array_key_exists('expectedPricing', $consent) || array_key_exists('maxChargeMillicents', $consent)) {
            $rates = is_array($consent['expectedPricing'] ?? null) && count($consent['expectedPricing']) === 4 ? self::classificationRates($consent['expectedPricing']) : null;
            if ($rates === null || !is_int($consent['maxChargeMillicents'] ?? null) || $consent['maxChargeMillicents'] < 0) {
                throw $missing;
            }
            $auth = ['expectedPricing' => $rates, 'maxChargeMillicents' => $consent['maxChargeMillicents']];
        } elseif (is_int($consent['expectedPriceMillicents'] ?? null) && $consent['expectedPriceMillicents'] >= 0 && self::strictAuthorization($supplied)) {
            $auth = ['expectedPricing' => self::classificationRates($supplied['expectedPricing']), 'maxChargeMillicents' => $supplied['maxChargeMillicents']];
            if ($auth['maxChargeMillicents'] !== $consent['expectedPriceMillicents']) {
                throw new PolicyException(409, 'PRICE_MISMATCH', 'priceAuthorization.maxChargeMillicents differs from the consented price');
            }
        } else {
            throw $missing;
        }
        if ($supplied !== null && !(self::strictAuthorization($supplied) && self::sameRates($supplied['expectedPricing'], $auth['expectedPricing']) && $supplied['maxChargeMillicents'] === $auth['maxChargeMillicents'])) {
            throw new PolicyException(409, 'PRICE_MISMATCH', 'body.priceAuthorization differs from the consented rates or ceiling');
        }

        return $auth;
    }

    private static function isMap(mixed $value): bool
    {
        return ($value instanceof \stdClass && get_object_vars($value) === []) || (is_array($value) && ($value === [] || !array_is_list($value)));
    }

    /**
     * Convert decoded JSON (objects as stdClass) into arrays while keeping empty
     * objects as stdClass so they re-encode as {} instead of [].
     */
    public static function normalize(mixed $value, int $depth = 0): mixed
    {
        if ($depth > 64) {
            throw new PolicyException(400, 'INVALID_JSON', 'JSON nesting is too deep');
        }
        if ($value instanceof \stdClass) {
            $vars = get_object_vars($value);
            if ($vars === []) {
                return $value;
            }
            return array_map(static fn (mixed $v): mixed => self::normalize($v, $depth + 1), $vars);
        }
        if (is_array($value)) {
            return array_map(static fn (mixed $v): mixed => self::normalize($v, $depth + 1), $value);
        }

        return $value;
    }

    private static function hasDef(array $defs, string $in, string $name): bool
    {
        foreach ($defs as $def) {
            if ($def['in'] === $in && $def['name'] === $name) {
                return true;
            }
        }

        return false;
    }

    private static function checkParam(array $def, mixed $value): string
    {
        if ($def['type'] === 'integer' || $def['type'] === 'number') {
            if (is_string($value) && preg_match('/\A\d{1,15}\z/', $value) === 1) {
                $value = (int) $value;
            }
            if (!is_int($value)) {
                throw new PolicyException(400, 'INVALID_PARAMETER', $def['name'].' must be a whole number');
            }
            if (isset($def['minimum']) && $value < $def['minimum']) {
                throw new PolicyException(400, 'INVALID_PARAMETER', $def['name'].' is below the minimum');
            }
            if (isset($def['maximum']) && $value > $def['maximum']) {
                throw new PolicyException(400, 'INVALID_PARAMETER', $def['name'].' exceeds the maximum');
            }

            return (string) $value;
        }
        if (!is_string($value) || $value === '' || strlen($value) > ($def['maxLength'] ?? 128)) {
            throw new PolicyException(400, 'INVALID_PARAMETER', $def['name'].' is invalid');
        }
        if (isset($def['enum']) && !in_array($value, $def['enum'], true)) {
            throw new PolicyException(400, 'INVALID_PARAMETER', $def['name'].' is not an allowed value');
        }
        if (isset($def['pattern']) && preg_match('/'.str_replace('/', '\/', $def['pattern']).'/D', $value) !== 1) {
            throw new PolicyException(400, 'INVALID_PARAMETER', $def['name'].' has an invalid format');
        }

        return $value;
    }
}
