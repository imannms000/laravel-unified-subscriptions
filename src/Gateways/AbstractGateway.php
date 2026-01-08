<?php

namespace Imannms000\LaravelUnifiedSubscriptions\Gateways;

use Imannms000\LaravelUnifiedSubscriptions\Events\SubscriptionCreated;
use Imannms000\LaravelUnifiedSubscriptions\Events\SubscriptionCanceled;
use Imannms000\LaravelUnifiedSubscriptions\Events\SubscriptionRenewed;
use Illuminate\Http\Request;
use Imannms000\LaravelUnifiedSubscriptions\Models\Subscription;

abstract class AbstractGateway
{
    protected string $name;

    public function __construct()
    {
        $this->name = strtolower(class_basename(static::class));
    }

    public function getName(): string
    {
        return $this->name;
    }

    protected function markSubscriptionAsActive(Subscription $subscription, string $gatewayId, ?\Carbon\Carbon $endsAt = null): void
    {
        $subscription->update([
            'gateway' => $this->getName(),
            'gateway_id' => $gatewayId,
            'starts_at' => now(),
            'ends_at' => $endsAt,
            'trial_ends_at' => $subscription->plan->trial_days ? now()->addDays($subscription->plan->trial_days) : null,
            'renewal_count' => 0,
        ]);

        event(new SubscriptionCreated($subscription));
    }

    protected function markSubscriptionAsCanceled(Subscription $subscription): void
    {
        $subscription->cancel(immediately: true);
        event(new SubscriptionCanceled($subscription));
    }

    protected function markSubscriptionAsRenewed(Subscription $subscription, ?\Carbon\Carbon $endsAt): void
    {
        if ($endsAt === null) {
            // Gateway didn't provide next date → calculate from current state
            $from = $subscription->ends_at ?? $subscription->starts_at ?? now();
            $endsAt = $from->add(
                $subscription->plan->interval->value . 's',
                $subscription->plan->interval_count ?? 1
            );
        }
        
        // Update ends_at and increment renewal_count atomically
        // it's atomic at database level → safe if multiple webhooks hit
        // and avoids race conditions
        $subscription->updateEndsAt($endsAt);
        $subscription->incrementRenewalCount();
        event(new SubscriptionRenewed($subscription));
    }
}