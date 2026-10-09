<?php

declare(strict_types=1);

namespace SendRepute\Laravel\CustomerApi;

/** Minimal session abstraction so the console logic is framework-neutral. */
interface ConsoleSession
{
    public function get(string $key, mixed $default = null): mixed;

    public function put(string $key, mixed $value): void;
}
