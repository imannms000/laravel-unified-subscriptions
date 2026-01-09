<?php

namespace Imannms000\LaravelUnifiedSubscriptions\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Imannms000\LaravelUnifiedSubscriptions\Enums\BillingInterval;

class Plan extends Model
{
    use HasFactory, HasUlids;

    protected $guarded = [];

    protected $casts = [
        'interval' => BillingInterval::class,
        'active' => 'boolean',
        'trial_days' => 'integer',
        'grace_days' => 'integer',
    ];

    public function features(): HasMany
    {
        return $this->hasMany(PlanFeature::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function gatewayPrices(): HasMany
    {
        return $this->hasMany(PlanGatewayPrice::class);
    }

    /**
     * Get price for a specific gateway, fallback to default price
     */
    public function getPriceForGateway(string $gateway): float
    {
        $gatewayPrice = $this->gatewayPrices()
            ->where('gateway', $gateway)
            ->first();

        return $gatewayPrice?->price ?? $this->price;
    }

    /**
     * Get currency for a specific gateway, fallback to default
     */
    public function getCurrencyForGateway(string $gateway): string
    {
        $gatewayPrice = $this->gatewayPrices()
            ->where('gateway', $gateway)
            ->first();

        return $gatewayPrice?->currency ?? $this->currency;
    }
}