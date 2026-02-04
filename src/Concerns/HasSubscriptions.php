<?php

namespace Imannms000\LaravelUnifiedSubscriptions\Concerns;

use Imannms000\LaravelUnifiedSubscriptions\Models\Subscription;
use Imannms000\LaravelUnifiedSubscriptions\Models\Plan;
use Carbon\Carbon;
use Hashids\Hashids;
use Illuminate\Support\Facades\Log;

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

    public function subscribedTo(Plan|string $plan): bool
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

    /**
     * Generate a short, reversible, obfuscated ID for Google Play Billing.
     * This is sent to the app → passed to Google → returned in RTDN.
     */
    public function getGoogleObfuscatedAccountId(): string
    {
        // Use a dedicated, stable salt (never rotate this!)
        $salt = config('subscription.gateways.google.obfuscation_salt', 'default-stable-salt-change-this-in-prod');

        $hashids = new Hashids(
            $salt,           // your secret salt
            10,              // min length (adjust as needed, Google allows up to 64 chars)
            'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ1234567890' // safe alphabet
        );

        // Encode user ID only (you can add more values if needed)
        return $hashids->encode($this->getKey()); // $this->id
    }

    /**
     * Reverse lookup: Find a user by the obfuscated ID received from Google RTDN.
     * Returns the first matching user or null.
     */
    public static function findByGoogleObfuscatedId(string $obfuscatedId): ?self
    {
        // Same salt & config as generation
        $salt = config('subscription.gateways.google.obfuscation_salt', 'default-stable-salt-change-this-in-prod');

        $hashids = new Hashids(
            $salt,
            10,
            'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ1234567890'
        );

        $decoded = $hashids->decode($obfuscatedId);

        if (empty($decoded)) {
            Log::warning('Invalid or tampered Google obfuscatedAccountId received', [
                'obfuscated_id' => $obfuscatedId,
            ]);
            return null;
        }

        // First decoded value is the user ID
        $userId = $decoded[0];

        $user = self::find($userId);

        if (!$user) {
            Log::warning('User not found for Google obfuscatedAccountId', [
                'obfuscated_id' => $obfuscatedId,
                'decoded_user_id' => $userId,
            ]);
        }

        return $user;
    }
}