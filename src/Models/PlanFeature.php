<?php

namespace Imannms000\LaravelUnifiedSubscriptions\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Imannms000\LaravelUnifiedSubscriptions\Enums\BillingInterval;

class PlanFeature extends Model
{
    protected $guarded = [];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }
}