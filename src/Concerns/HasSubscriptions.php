<?php

namespace Imannms000\LaravelUnifiedSubscriptions\Concerns;

use Imannms000\LaravelUnifiedSubscriptions\Models\Subscription;
use Imannms000\LaravelUnifiedSubscriptions\Models\Plan;
use Carbon\Carbon;

use function Illuminate\Support\now;

trait HasSubscriptions
{
    public function subscriptions()
    {
        return $this->morphMany(Subscription::class, 'subscribable');
    }

    public function activeSubscription(): ?Subscription
    {
        return $this->subscriptions()
            ->where(function ($query) {
                $query->whereNull('ends_at')
                      ->orWhere('ends_at', '>', now());
            })
            ->whereNull('canceled_at')
            ->orderByDesc('created_at')
            ->first();
    }

    public function subscribedTo(Plan|int $plan): bool
    {
        $planId = $plan instanceof Plan ? $plan->id : $plan;

        return $this->subscriptions()
            ->where('plan_id', $planId)
            ->where(function ($query) {
                $query->whereNull('ends_at')
                      ->orWhere('ends_at', '>', now());
            })
            ->whereNull('canceled_at')
            ->exists();
    }

    public function onTrial(): bool
    {
        $subscription = $this->activeSubscription();

        return $subscription?->trial_ends_at?->isFuture() ?? false;
    }

    public function onGracePeriod(): bool
    {
        $subscription = $this->activeSubscription();

        return $subscription?->ends_at?->isPast() &&
               $subscription?->grace_ends_at?->isFuture();
    }

    public function hasActiveSubscription(): bool
    {
        return $this->activeSubscription()?->isActive() ?? false;
    }

    public function canUseFeature(string $slug, int $quantity = 1): bool
    {
        $subscription = $this->activeSubscription();

        if (! $subscription || ! $subscription->isActive()) {
            return false;
        }

        return $subscription->canUseFeature($slug, $quantity);
    }

    public function recordFeatureUsage(string $slug, int $quantity = 1): void
    {
        $subscription = $this->activeSubscription();

        if ($subscription && $subscription->isActive()) {
            $subscription->recordFeatureUsage($slug, $quantity);
        }
    }

    public function remainingFeatureUsage(string $slug): int
    {
        $subscription = $this->activeSubscription();

        return $subscription?->remainingFeatureUsage($slug) ?? 0;
    }
}