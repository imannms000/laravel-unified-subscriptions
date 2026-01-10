<?php

namespace Imannms000\LaravelUnifiedSubscriptions;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;

class LaravelUnifiedSubscriptionsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../migrations');

        $this->publishes([
            __DIR__.'/../config.php' => config_path('subscription.php'),
        ], 'subscription-config');

        $this->publishes([
            __DIR__.'/../migrations' => database_path('migrations'),
        ], 'subscription-migrations');

        $this->publishes([
            __DIR__.'/../resources/apple_root_g3.pem' => storage_path('app/apple_root.pem'),
        ], 'apple-cert');

        $this->loadRoutesFrom(__DIR__.'/../routes/api.php');
        $this->loadRoutesFrom(__DIR__.'/../routes/webhooks.php');

        // Relation::enforceMorphMap([
        //     'user' => config('subscription.models.user', \App\Models\User::class),
        // ]);

        if ($this->app->runningInConsole()) {
			$this->commands([
				\Imannms000\LaravelUnifiedSubscriptions\Commands\CreateSubscriptionPlanCommand::class,
                \Imannms000\LaravelUnifiedSubscriptions\Commands\ListSubscriptionPlansCommand::class,
                \Imannms000\LaravelUnifiedSubscriptions\Commands\UpdateSubscriptionPlanCommand::class,
                \Imannms000\LaravelUnifiedSubscriptions\Commands\DeleteSubscriptionPlanCommand::class,
                
                \Imannms000\LaravelUnifiedSubscriptions\Commands\ListSubscriptionsCommand::class,

                \Imannms000\LaravelUnifiedSubscriptions\Commands\FakeCreateSubscriptionCommand::class,
                \Imannms000\LaravelUnifiedSubscriptions\Commands\FakeRenewSubscriptionCommand::class,
                \Imannms000\LaravelUnifiedSubscriptions\Commands\FakeCancelSubscriptionCommand::class,
                \Imannms000\LaravelUnifiedSubscriptions\Commands\FakeExpireSubscriptionCommand::class,
                \Imannms000\LaravelUnifiedSubscriptions\Commands\ProcessFakeRenewalsCommand::class,
			]);
		}

        $this->app->booted(function () {
            $schedule = $this->app->make(\Illuminate\Console\Scheduling\Schedule::class);
            // $schedule->job(new RenewSubscriptionsJob)->daily();

            if (config('subscription.gateways.fake.enabled') && config('subscription.gateways.fake.auto_renew.enabled')) {
                $schedule->command('subscription:fake:process-renewals')->everyMinute()->withoutOverlapping(3);
            }
        });

        $this->app->singleton(GatewayManager::class);
    }

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config.php', 'subscription');
    }
}