<?php

namespace Imannms000\LaravelUnifiedSubscriptions\Http\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\Http\JsonResponse;
use Exception;
use Imannms000\LaravelUnifiedSubscriptions\Http\Requests\StoreXenditSubscriptionRequest;
use Imannms000\LaravelUnifiedSubscriptions\Models\Plan;
use Imannms000\LaravelUnifiedSubscriptions\Models\Subscription;
use Imannms000\LaravelUnifiedSubscriptions\Concerns\HasSubscriptions;
use Imannms000\LaravelUnifiedSubscriptions\Enums\Gateway;
use Imannms000\LaravelUnifiedSubscriptions\Gateways\XenditGateway;

class XenditSubscriptionController extends Controller
{
    public function store(StoreXenditSubscriptionRequest $request): JsonResponse
    {
        /** @var HasSubscriptions */
        $user = $request->user();
        $plan = Plan::findOrFail($request->plan_id);

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
            'gateway' => Gateway::XENDIT->value,
            'starts_at' => now(),
        ]);

        try {
            /** @var XenditGateway */
            $gateway = app(XenditGateway::class);
            $response = $gateway->createSubscription($subscription);
            $action_url = $gateway->getActionUrl($response);

            return response()->json([
                'success' => true,
                'message' => 'Xendit recurring payment created. User must complete payment.',
                'data' => [
                    'subscriptionId' => $subscription->id,
                    'referenceId' => $response['reference_id'],
                    'customerId' => $response['customer_id'],
                    'currency' => $response['currency'],
                    'amount' => $response['amount'],
                    'actionUrl' => $action_url,
                ],
            ], 201);
        } catch (Exception $e) {
            $subscription->delete();

            return response()->json([
                'error' => 'Failed to create Xendit recurring payment: ' . $e->getMessage(),
            ], 500);
        }
    }

    // Reuse index() and cancel() from PayPal example
}