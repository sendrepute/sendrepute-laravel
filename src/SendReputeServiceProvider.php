<?php

namespace SendRepute\Laravel;

use GuzzleHttp\Client;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Event;
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
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/sendrepute.php' => config_path('sendrepute.php'),
        ], 'sendrepute-config');

        Event::listen(MessageSending::class, AnalyzeOutgoingMessage::class);
    }
}