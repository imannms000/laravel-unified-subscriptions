<?php

namespace Imannms000\LaravelUnifiedSubscriptions\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Routing\Controller;
use Illuminate\Support\Collection;
use Imannms000\LaravelUnifiedSubscriptions\Enums\Gateway;
use Imannms000\LaravelUnifiedSubscriptions\Gateways\PayPalGateway;
use Imannms000\LaravelUnifiedSubscriptions\Http\Resources\SubscriptionResource;
use Imannms000\LaravelUnifiedSubscriptions\Models\Subscription;

class SubscriptionController extends Controller
{
    /**
     * Get user's subscriptions (status updates via webhook)
     */
    public function index(Request $request): array
    {
        $subscriptions = $request->user()
        ->subscriptions()
        ->with(['plan'])
        ->withCount('transactions')
        ->when($request->filter === 'active', fn($q) => $q->active())
        ->when($request->filter === 'inactive', fn($q) => $q->inactive())
        ->latest()
        ->get();

        return SubscriptionResource::collection($subscriptions)->resolve();
    }

    /**
     * Status Check 
     */
    public function show(Request $request, Subscription $subscription): JsonResource
    {
        // $this->authorize('view', $subscription);

        $subscription->load('plan')->loadCount('transactions');
        
        return new SubscriptionResource($subscription);
    }

    /**
     * Cancel subscription (triggers cancel + webhook)
     */
    public function destroy(Request $request, Subscription $subscription): JsonResponse
    {
        // $this->authorize('cancel', $subscription);

        app($subscription->gateway->toGatewayClass())->cancelSubscription($subscription);

        return response()->json([
            'success' => true,
            'message' => 'Cancellation requested. Access until period end.',
        ]);
    }
}