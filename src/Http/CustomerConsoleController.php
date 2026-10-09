<?php

declare(strict_types=1);

namespace SendRepute\Laravel\Http;

use SendRepute\Laravel\CustomerApi\ConsoleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Operator console for the full customer API. Routes are registered only when
 * sendrepute.customer_api.console.enabled is true and run behind the configured
 * middleware (default: web, auth, can:sendrepute-customer-api). The package
 * never defines that gate, so access is denied until the application grants it.
 */
final class CustomerConsoleController
{
    public function __construct(private readonly ConsoleService $console)
    {
    }

    public function index(Request $request): Response
    {
        [$html, $csp] = ConsoleService::renderPage('/'.trim($request->route()->getPrefix() ?? '', '/'), (string) $request->session()->token(), 'Laravel');

        return new Response($html, 200, ConsoleService::securityHeaders() + ['Content-Type' => 'text/html; charset=UTF-8', 'Content-Security-Policy' => $csp]);
    }

    public function catalog(Request $request): JsonResponse
    {
        return new JsonResponse($this->console->catalog((string) $request->session()->token()), 200, ConsoleService::securityHeaders());
    }

    public function call(Request $request): JsonResponse
    {
        $origin = (string) $request->headers->get('Origin', '');
        if ($origin === '' || parse_url($origin, PHP_URL_HOST) !== $request->getHost()) {
            return $this->error(403, 'ORIGIN_REFUSED', 'Cross-origin console request refused');
        }
        $token = (string) $request->headers->get('X-CSRF-Token', '');
        if ($token === '' || !hash_equals((string) $request->session()->token(), $token)) {
            return $this->error(403, 'CSRF_REFUSED', 'CSRF validation failed');
        }
        if (!str_starts_with(strtolower((string) $request->headers->get('Content-Type', '')), 'application/json')) {
            return $this->error(415, 'JSON_REQUIRED', 'JSON body required');
        }
        [$status, $payload] = $this->console->handleRawCall((string) $request->getContent(), new LaravelConsoleSession($request->session()));

        return new JsonResponse($payload, $status, ConsoleService::securityHeaders());
    }

    private function error(int $status, string $code, string $message): JsonResponse
    {
        return new JsonResponse(['error' => ['code' => $code, 'message' => $message]], $status, ConsoleService::securityHeaders());
    }
}
