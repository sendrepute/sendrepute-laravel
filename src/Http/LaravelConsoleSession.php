<?php

declare(strict_types=1);

namespace SendRepute\Laravel\Http;

use SendRepute\Laravel\CustomerApi\ConsoleSession;
use Illuminate\Contracts\Session\Session;

final class LaravelConsoleSession implements ConsoleSession
{
    public function __construct(private readonly Session $session)
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->session->get($key, $default);
    }

    public function put(string $key, mixed $value): void
    {
        $this->session->put($key, $value);
    }
}
