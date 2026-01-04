<?php

namespace Imannms000\LaravelUnifiedSubscriptions\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Imannms000\LaravelUnifiedSubscriptions\Models\SubscriptionTransaction;

class SubscriptionPaymentFailed
{
    use Dispatchable, SerializesModels;

    public SubscriptionTransaction $transaction;

    public function __construct(SubscriptionTransaction $transaction)
    {
        $this->transaction = $transaction;
    }
}