<?php

declare(strict_types=1);

namespace SendRepute\Laravel\CustomerApi;

/**
 * Generated from artifacts/api-server/src/customer-api-openapi.json (49 operations).
 */
final class OperationCatalog
{
    private static ?array $data = null;

    public static function data(): array
    {
        if (self::$data === null) {
            $raw = file_get_contents(__DIR__.'/resources/customer-api-operations.json');
            self::$data = json_decode((string) $raw, true, 64, JSON_THROW_ON_ERROR);
        }

        return self::$data;
    }

    /** @return list<array<string, mixed>> */
    public static function operations(): array
    {
        return self::data()['operations'];
    }

    public static function apiVersion(): string
    {
        return (string) self::data()['apiVersion'];
    }

    public static function get(string $id): ?array
    {
        foreach (self::operations() as $op) {
            if ($op['id'] === $id) {
                return $op;
            }
        }

        return null;
    }

    public static function isQuoteOperation(string $id): bool
    {
        foreach (self::operations() as $op) {
            if (($op['quote'] ?? null) === $id) {
                return true;
            }
        }

        return false;
    }
}
