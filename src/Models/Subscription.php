<?php

namespace Imannms000\LaravelUnifiedSubscriptions\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Imannms000\LaravelUnifiedSubscriptions\Enums\Gateway;
use Imannms000\LaravelUnifiedSubscriptions\Events\SubscriptionPaymentFailed;
use Imannms000\LaravelUnifiedSubscriptions\Events\SubscriptionPaymentSucceeded;
use Imannms000\LaravelUnifiedSubscriptions\Events\SubscriptionTransactionCreated;
use Imannms000\LaravelUnifiedSubscriptions\Gateways\FakeGateway;

class Subscription extends Model
{
    use HasFactory, HasUlids;

    protected $guarded = [];

    protected $casts = [
        'gateway' => Gateway::class,
        'gateway_response' => 'array',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'trial_ends_at' => 'datetime',
        'canceled_at' => 'datetime',
        'grace_ends_at' => 'datetime',
    ];

    public function subscribable(): MorphTo
    {
        return $this->morphTo();
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function usage(): HasMany
    {
        return $this->hasMany(SubscriptionUsage::class);
    }

    public function isActive(): bool
    {
        if ($this->canceled_at) {
            return false;
        }

        if ($this->trial_ends_at?->isFuture()) {
            return true;
        }

        return $this->ends_at === null || $this->ends_at->isFuture();
    }

    public function renew(): void
    {
        if (! $this->isActive()) {
            return;
        }

        $plan = $this->plan;
        $interval = $plan->interval;
        $count = $plan->interval_count ?? 1;

        $from = $this->ends_at ?? $this->starts_at ?? now();

        $method = $interval->toCarbonMethod();
        $nextEndsAt = $from->$method($count);

        $this->update([
            'ends_at' => $nextEndsAt,
        ]);

        $transaction = $this->recordTransaction(
            'renewal',
            $this->plan->getPriceForGateway($this->gateway),
            $this->plan->getCurrencyForGateway($this->gateway)
        );

        event(new \Imannms000\LaravelUnifiedSubscriptions\Events\SubscriptionRenewed($this, $transaction));
    }

    public function cancel(bool $immediately = false): void
    {
        if ($immediately) {
            $this->update([
                'canceled_at' => now(),
                'ends_at' => now(),
            ]);
        } else {
            $this->update(['canceled_at' => now()]);
        }

        event(new \Imannms000\LaravelUnifiedSubscriptions\Events\SubscriptionCanceled($this));
    }

    public function resume(): void
    {
        if (! $this->canceled_at) {
            return;
        }

        $this->update(['canceled_at' => null]);

        event(new \Imannms000\LaravelUnifiedSubscriptions\Events\SubscriptionResumed($this));
    }

    public function swap(Plan $newPlan): void
    {
        $this->update(['plan_id' => $newPlan->id]);

        // Reset usage if plan features differ
        $this->usage()->delete();

        event(new \Imannms000\LaravelUnifiedSubscriptions\Events\SubscriptionSwapped($this, $newPlan));
    }

    public function canUseFeature(string $slug, int $quantity = 1): bool
    {
        $feature = $this->plan->features()->where('slug', $slug)->first();

        if (! $feature) {
            return true; // Unlimited if not defined
        }

        $used = $this->usage()
            ->where('feature_slug', $slug)
            ->sum('used');

        $limit = $feature->value;

        return ($used + $quantity) <= $limit;
    }

    public function recordFeatureUsage(string $slug, int $quantity = 1): void
    {
        if (! $this->canUseFeature($slug, $quantity)) {
            throw new \Exception("Feature limit exceeded for {$slug}");
        }

        $this->usage()->create([
            'feature_slug' => $slug,
            'used' => $quantity,
        ]);

        event(new \Imannms000\LaravelUnifiedSubscriptions\Events\FeatureUsed($this, $slug, $quantity));
    }

    public function remainingFeatureUsage(string $slug): int
    {
        $feature = $this->plan->features()->where('slug', $slug)->first();

        if (! $feature) {
            return PHP_INT_MAX;
        }

        $used = $this->usage()
            ->where('feature_slug', $slug)
            ->sum('used');

        return max(0, $feature->value - $used);
    }

    public function resetUsage(): void
    {
        $this->usage()->delete();
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(SubscriptionTransaction::class);
    }

    public function recordTransaction(string $type, float $amount, string $currency, ?string $gatewayTransactionId = null, string $status = 'completed', array $metadata = []): SubscriptionTransaction
    {
        $transaction = $this->transactions()->create([
            'gateway' => $this->gateway,
            'gateway_transaction_id' => $gatewayTransactionId,
            'type' => $type,
            'amount' => $amount,
            'currency' => $currency,
            'status' => $status,
            'metadata' => $metadata,
        ]);

        event(new SubscriptionTransactionCreated($transaction));
        
        if ($status === 'completed' && in_array($type, ['payment', 'renewal'])) {
            event(new SubscriptionPaymentSucceeded($transaction));
        } elseif ($status === 'failed') {
            event(new SubscriptionPaymentFailed($transaction));
        }

        return $transaction;
    }

    public function updateEndsAt(\Carbon\Carbon $endsAt)
    {
        $this->update(['ends_at' => $endsAt]);
    }

    public function incrementRenewalCount()
    {
        $this->increment('renewal_count');
    }

    public function resetRenewalCount()
    {
        $this->update(['renewal_count' => 0]);
    }

    public function scopeFakeDueForRenewal($query)
    {
        return $query->where('gateway', 'fake')
                    ->whereNull('canceled_at')
                    ->whereNotNull('ends_at')
                    ->where('ends_at', '<=', now());
    }

    /**
     * Scope a query to only include active subscriptions.
     * Active: Not canceled AND (Trial is future OR EndsAt is null/future)
     */
    public function scopeActive($query)
    {
        return $query->whereNull('canceled_at')
            ->where(function ($q) {
                $q->where('trial_ends_at', '>', now())
                  ->orWhereNull('ends_at')
                  ->orWhere('ends_at', '>', now());
            });
    }

    /**
     * Scope a query to only include inactive subscriptions.
     */
    public function scopeInactive($query)
    {
        return $query->where(function ($q) {
            $q->whereNotNull('canceled_at')
              ->orWhere(function ($subQ) {
                  $subQ->where(function ($trialQ) {
                      $trialQ->whereNull('trial_ends_at')
                             ->orWhere('trial_ends_at', '<=', now());
                  })
                  ->whereNotNull('ends_at')
                  ->where('ends_at', '<=', now());
              });
        });
    }

    public static function processFakeRenewals(): void
    {
        if (!config('subscription.fake.enabled') || !config('subscription.fake.auto_renew.enabled')) {
            \Log::info('[FakeGateway] Auto-renew disabled or fake gateway not enabled.');
            return;
        }

        $gateway = app(FakeGateway::class);

        $count = static::fakeDueForRenewal()->count();

        if ($count === 0) {
            return;
        }

        \Log::info("[FakeGateway] Processing {$count} subscription(s) due for renewal.");

        static::fakeDueForRenewal()
            ->chunk(50, function ($subscriptions) use ($gateway) {
                foreach ($subscriptions as $subscription) {
                    $gateway->renewSubscription($subscription);
                }
            });
    }

    public function getGoogleExpiryFromResponse()
    {
        $latestLineItem = $subscription->gateway_response['lineItems'][0] ?? null;
        $expirySeconds = $latestLineItem['expiryTime']['seconds'] ?? null;
        return $expirySeconds;
    }
}