<?php

namespace Imannms000\LaravelUnifiedSubscriptions\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class SubscriptionResource extends JsonResource
{
    public static $wrap = null;
    
    public function toArray($request)
    {
        return [
            'id'                => $this->id,
            'status'            => $this->isActive() ? 'active' : 'inactive',
            'tier'              => $this->plan->tier,
            'plan'              => $this->plan->name,
            'endsAt'            => $this->ends_at?->toDateString(),
            'gatewayId'         => $this->gateway_id,
            'trialEndsAt'       => $this->trial_ends_at?->toDateString(),
            'transactionsCount' => $this->transactions_count,
            'renewalCount'      => $this->renewal_count,
            'gatewayResponse'   => $this->gateway_response,
        ];
    }
}