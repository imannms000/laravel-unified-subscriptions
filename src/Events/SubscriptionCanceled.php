<?php

namespace Imannms000\LaravelUnifiedSubscriptions\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Imannms000\LaravelUnifiedSubscriptions\Models\Subscription;

class SubscriptionCanceled
{
    use Dispatchable, SerializesModels;

    public Subscription $subscription;
    public bool $immediate;

    public function __construct(Subscription $subscription, bool $immediate = false)
    {
        $this->subscription = $subscription;
        $this->immediate = $immediate;
    }
}