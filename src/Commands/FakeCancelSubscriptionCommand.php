<?php

namespace Imannms000\LaravelUnifiedSubscriptions\Commands;

use Illuminate\Console\Command;
use Imannms000\LaravelUnifiedSubscriptions\Gateways\FakeGateway;
use Imannms000\LaravelUnifiedSubscriptions\Models\Plan;
use Imannms000\LaravelUnifiedSubscriptions\Models\Subscription;

class FakeCancelSubscriptionCommand extends Command
{
    protected $signature = 'subscription:fake:cancel {subscriptionId}';
    protected $description = 'Cancel a fake subscription';

    public function handle(): int
    {
        $subscription = Subscription::findOrFail($this->argument('subscriptionId'));
        app(FakeGateway::class)->cancelSubscription($subscription);
        $this->info("Fake subscription {$subscription->id} canceled.");
        return self::SUCCESS;
    }
}