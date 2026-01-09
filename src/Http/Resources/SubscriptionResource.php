<?php

namespace Imannms000\LaravelUnifiedSubscriptions\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class SubscriptionResource extends JsonResource
{
    public static $wrap = null;
    
    public function toArray($request)
    {
        return [
            'id'              => $this->id,
            'status'          => $this->isActive() ? 'active' : 'inactive',
            'tier'            => $this->plan->tier,
            'plan'            => $this->plan->name,
            'ends_at'         => $this->ends_at?->toDateString(),
            'gateway_id'      => $this->gateway_id,
            'trial_ends_at'   => $this->trial_ends_at?->toDateString(),
            'transactions_count' => $this->transactions_count,
            'renewal_count'   => $this->renewal_count,
            'gateway_response' => $this->gateway_response,
        ];
    }
}