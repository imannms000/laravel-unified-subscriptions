<?php

namespace Imannms000\LaravelUnifiedSubscriptions\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Imannms000\LaravelUnifiedSubscriptions\Models\Subscription;

class FeatureLimitExceeded
{
    use Dispatchable, SerializesModels;

    public Subscription $subscription;
    public string $featureSlug;
    public int $requestedQuantity;
    public int $remaining;

    public function __construct(Subscription $subscription, string $featureSlug, int $requestedQuantity, int $remaining)
    {
        $this->subscription = $subscription;
        $this->featureSlug = $featureSlug;
        $this->requestedQuantity = $requestedQuantity;
        $this->remaining = $remaining;
    }
}