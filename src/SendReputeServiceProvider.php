<?php

namespace SendRepute\Laravel;

use GuzzleHttp\Client;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use SendRepute\Laravel\Contracts\Classifier;
use SendRepute\Laravel\Listeners\AnalyzeOutgoingMessage;

final class SendReputeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/sendrepute.php', 'sendrepute');

        $this->app->singleton(SendReputeClassifier::class, fn ($app) => new SendReputeClassifier(
            new Client(),
            $app['config']->get('sendrepute', []),
        ));
        $this->app->alias(SendReputeClassifier::class, Classifier::class);
        $this->app->singleton(CustomerApi\CustomerApiClient::class, fn ($app) => new CustomerApi\CustomerApiClient(
            new CustomerApi\GuzzleTransport(new Client()),
            (string) $app['config']->get('sendrepute.customer_api.api_key', ''),
            $app['config']->get('sendrepute.customer_api.base_url'),
            (array) $app['config']->get('sendrepute.customer_api.trusted_hosts', []),
            (float) $app['config']->get('sendrepute.customer_api.timeout_seconds', 20.0),
            (int) $app['config']->get('sendrepute.customer_api.max_response_bytes', 8388608),
        ));
        $this->app->singleton(CustomerApi\ConsoleService::class, fn ($app) => new CustomerApi\ConsoleService(
            $app->make(CustomerApi\CustomerApiClient::class),
            'Laravel',
            $app['config']->get('sendrepute.customer_api.console.handoff_return_origin') ?: null,
            $app['config']->get('sendrepute.customer_api.console.enabled_operations'),
            self::intentStore($app, (array) $app['config']->get('sendrepute.customer_api.console.intent_store', [])),
        ));
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/sendrepute.php' => config_path('sendrepute.php'),
        ], 'sendrepute-config');

        Event::listen(MessageSending::class, AnalyzeOutgoingMessage::class);

        if ((bool) $this->app['config']->get('sendrepute.customer_api.console.enabled', false)) {
            $path = trim((string) $this->app['config']->get('sendrepute.customer_api.console.path', 'sendrepute/customer-api'), '/');
            if (preg_match('#\A[A-Za-z0-9/_-]{1,80}\z#', $path) !== 1) {
                throw new \InvalidArgumentException('Invalid SendRepute console path.');
            }
            $middleware = (array) $this->app['config']->get('sendrepute.customer_api.console.middleware', ['web', 'auth', 'can:sendrepute-customer-api']);
            Route::middleware($middleware)->prefix($path)->group(function (): void {
                Route::get('/', [Http\CustomerConsoleController::class, 'index']);
                Route::get('/catalog', [Http\CustomerConsoleController::class, 'catalog']);
                Route::post('/call', [Http\CustomerConsoleController::class, 'call']);
            });
        }
    }

    private static function intentStore($app, array $config): ?CustomerApi\IntentLedger
    {
        return match ($config['driver'] ?? null) {
            null, '' => null,
            'cache' => new CustomerApi\LaravelCacheIntentStore(
                $app['cache']->store($config['cache_store'] ?? null),
                (string) $app['config']->get('cache.stores.'.($config['cache_store'] ?? $app['config']->get('cache.default')).'.driver', ''),
            ),
            'filesystem' => new CustomerApi\FileIntentStore((string) ($config['path'] ?? ''), ($config['single_host'] ?? false) === true),
            default => throw new \InvalidArgumentException('Unsupported SendRepute intent store driver.'),
        };
    }
}
