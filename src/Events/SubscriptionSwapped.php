<?php

namespace Imannms000\LaravelUnifiedSubscriptions\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Imannms000\LaravelUnifiedSubscriptions\Models\Plan;
use Imannms000\LaravelUnifiedSubscriptions\Models\Subscription;

class SubscriptionSwapped
{
    use Dispatchable, SerializesModels;

    public Subscription $subscription;
    public Plan $oldPlan;
    public Plan $newPlan;

    public function __construct(Subscription $subscription, Plan $newPlan)
    {
        $this->subscription = $subscription;
        $this->oldPlan = $subscription->plan()->first();
        $this->newPlan = $newPlan;
    }
}