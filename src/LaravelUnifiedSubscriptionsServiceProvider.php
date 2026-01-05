<?php

namespace Imannms000\LaravelUnifiedSubscriptions;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;
use Imannms000\LaravelUnifiedSubscriptions\Jobs\RenewSubscriptionsJob;

class LaravelUnifiedSubscriptionsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->publishes([
            __DIR__.'/../config.php' => config_path('subscription.php'),
        ], 'subscription-config');

        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'subscription-migrations');

        $this->publishes([
            __DIR__.'/../resources/apple_root_g3.pem' => storage_path('app/apple_root.pem'),
        ], 'apple-cert');

        $this->loadRoutesFrom(__DIR__.'/../routes/api.php');
        $this->loadRoutesFrom(__DIR__.'/../routes/webhooks.php');

        // Relation::enforceMorphMap([
        //     'user' => config('subscription.models.user', \App\Models\User::class),
        // ]);

        // Daily renewal job
        if ($this->app->runningInConsole()) {
            $this->app->booted(function () {
                $schedule = $this->app->make(\Illuminate\Console\Scheduling\Schedule::class);
                $schedule->job(new RenewSubscriptionsJob)->daily();
            });
        }

        $this->app->singleton(GatewayManager::class);
    }

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/subscription.php', 'subscription');
    }
}