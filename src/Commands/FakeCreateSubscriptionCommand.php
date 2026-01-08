<?php

namespace Imannms000\LaravelUnifiedSubscriptions\Commands;

use Illuminate\Console\Command;
use Imannms000\LaravelUnifiedSubscriptions\Gateways\FakeGateway;
use Imannms000\LaravelUnifiedSubscriptions\Models\Plan;

class FakeCreateSubscriptionCommand extends Command
{
    protected $signature = 'subscription:fake:create {userId} {planSlug}';
    protected $description = 'Create a fake subscription instantly';

    public function handle(): int
    {
        $user = config('subscription.models.user')::findOrFail($this->argument('userId'));
        $plan = Plan::where('slug', $this->argument('planSlug'))->firstOrFail();

        $subscription = $user->subscriptions()->create([
            'plan_id' => $plan->id,
            'gateway' => 'fake',
        ]);

        app(FakeGateway::class)->createSubscription($subscription);

        $this->info("Fake subscription created for user {$user->id} on plan '{$plan->name}'");

        return self::SUCCESS;
    }
}