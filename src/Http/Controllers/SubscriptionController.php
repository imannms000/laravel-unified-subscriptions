<?php

namespace Imannms000\LaravelUnifiedSubscriptions\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Imannms000\LaravelUnifiedSubscriptions\Enums\Gateway;
use Imannms000\LaravelUnifiedSubscriptions\Gateways\PayPalGateway;
use Imannms000\LaravelUnifiedSubscriptions\Models\Subscription;

class SubscriptionController extends Controller
{
    /**
     * Get user's subscriptions (status updates via webhook)
     */
    public function index(Request $request): JsonResponse
    {
        $subscriptions = $request->user()
            ->subscriptions()
            ->with(['plan', 'transactions'])
            ->latest()
            ->get();

        return response()->json([
            'data' => $subscriptions->map(fn($sub) => [
                'id' => $sub->id,
                'status' => $sub->isActive() ? 'active' : 'inactive',
                'plan' => $sub->plan->name,
                'ends_at' => $sub->ends_at?->toDateString(),
                'gateway_id' => $sub->gateway_id,
                'trial_ends_at' => $sub->trial_ends_at?->toDateString(),
                'transactions_count' => $sub->transactions()->count(),
            ]),
        ]);
    }

    /**
     * Status Check 
     */
    public function show(Request $request, Subscription $subscription): JsonResponse
    {
        // $this->authorize('view', $subscription);
        
        return response()->json([
            'id' => $subscription->id,
            'status' => $subscription->isActive() ? 'active' : 'pending',
            'gateway_id' => $subscription->gateway_id,
            'can_use_features' => $subscription->isActive(),
        ]);
    }

    /**
     * Cancel subscription (triggers cancel + webhook)
     */
    public function destroy(Request $request, Subscription $subscription): JsonResponse
    {
        // $this->authorize('cancel', $subscription);

        app(Gateway::from($subscription->gateway)->toGatewayClass())->cancelSubscription($subscription);

        return response()->json([
            'success' => true,
            'message' => 'Cancellation requested. Access until period end.',
        ]);
    }
}