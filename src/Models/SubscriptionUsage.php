<?php

namespace Imannms000\LaravelUnifiedSubscriptions\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Imannms000\LaravelUnifiedSubscriptions\Models\Subscription;

class SubscriptionUsage extends Model
{
    protected $guarded = [];

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }
}