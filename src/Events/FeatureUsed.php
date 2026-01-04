<?php

namespace Imannms000\LaravelUnifiedSubscriptions\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Imannms000\LaravelUnifiedSubscriptions\Models\Subscription;

class FeatureUsed
{
    use Dispatchable, SerializesModels;

    public Subscription $subscription;
    public string $featureSlug;
    public int $quantity;

    public function __construct(Subscription $subscription, string $featureSlug, int $quantity)
    {
        $this->subscription = $subscription;
        $this->featureSlug = $featureSlug;
        $this->quantity = $quantity;
    }
}