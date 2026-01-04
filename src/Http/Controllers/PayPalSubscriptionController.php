<?php

namespace Imannms000\LaravelUnifiedSubscriptions\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Imannms000\LaravelUnifiedSubscriptions\Concerns\HasSubscriptions;
use Imannms000\LaravelUnifiedSubscriptions\Gateways\PayPalGateway;
use Imannms000\LaravelUnifiedSubscriptions\Http\Requests\StorePayPalSubscriptionRequest;
use Imannms000\LaravelUnifiedSubscriptions\Models\Plan;
use Imannms000\LaravelUnifiedSubscriptions\Models\Subscription;

class PayPalSubscriptionController extends Controller
{
    /**
     * Create PayPal subscription (returns approval URL for mobile/web client)
     */
    public function store(StorePayPalSubscriptionRequest $request): JsonResponse
    {
        /** @var HasSubscriptions */
        $user = $request->user(); // Sanctum/API auth required
        $plan = Plan::findOrFail($request->plan_id);

        // Policy check
        // $this->authorize('create', [Subscription::class, $user]);

        // Prevent duplicate active subs
        if ($user->hasActiveSubscription()) {
            return response()->json([
                'error' => 'You already have an active subscription.',
            ], 409);
        }

        // Create pending subscription
        $subscription = $user->subscriptions()->create([
            'plan_id' => $plan->id,
            'gateway' => Gateway::PAYPAL->value,
            'starts_at' => now(), // optimistic
        ]);

        try {
            /** @var PayPalGateway */
            $gateway = app(PayPalGateway::class);
            $paypalResponse = $gateway->createSubscription($subscription);

            return response()->json([
                'success' => true,
                'message' => 'PayPal checkout initiated. Redirect user to approval URL.',
                'data' => [
                    'subscription_id' => $subscription->id,
                    'approval_url' => collect($paypalResponse['links'] ?? [])
                        ->firstWhere('rel', 'approve')['href'] ?? null,
                    'subscription_id_paypal' => $paypalResponse['id'] ?? null,
                ],
            ]);
        } catch (\Exception $e) {
            // Cleanup on gateway failure
            $subscription->delete();
            
            return response()->json([
                'error' => 'Failed to create PayPal subscription: ' . $e->getMessage(),
            ], 500);
        }
    }
}