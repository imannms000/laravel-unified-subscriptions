<?php

namespace Imannms000\LaravelUnifiedSubscriptions\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Imannms000\LaravelUnifiedSubscriptions\Models\Subscription;
use Imannms000\LaravelUnifiedSubscriptions\Models\SubscriptionTransaction;

class SubscriptionRenewed
{
    use Dispatchable, SerializesModels;

    public Subscription $subscription;
    public ?SubscriptionTransaction $transaction;

    public function __construct(Subscription $subscription, ?SubscriptionTransaction $transaction = null)
    {
        $this->subscription = $subscription;
        $this->transaction = $transaction;
    }
}