<?php

namespace Imannms000\LaravelUnifiedSubscriptions\Commands;

use Illuminate\Console\Command;
use Imannms000\LaravelUnifiedSubscriptions\Gateways\FakeGateway;
use Imannms000\LaravelUnifiedSubscriptions\Models\Plan;
use Imannms000\LaravelUnifiedSubscriptions\Models\Subscription;

class FakeRenewSubscriptionCommand extends Command
{
    protected $signature = 'subscription:fake:renew {subscriptionId}';
    protected $description = 'Manually renew a fake subscription';

    public function handle(): int
    {
        $subscription = Subscription::findOrFail($this->argument('subscriptionId'));

        if ($subscription->gateway->value !== 'fake') {
            $this->error('Only fake subscriptions can be manually renewed.');
            return self::FAILURE;
        }

        app(FakeGateway::class)->renewSubscription($subscription);

        $this->info("Fake subscription {$subscription->id} manually renewed.");

        return self::SUCCESS;
    }
}