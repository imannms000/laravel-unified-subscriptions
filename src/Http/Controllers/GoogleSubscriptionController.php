<?php

namespace Imannms000\LaravelUnifiedSubscriptions\Http\Controllers;

use Imannms000\LaravelUnifiedSubscriptions\Gateways\GooglePlayGateway;
use Imannms000\LaravelUnifiedSubscriptions\Models\Plan;
use Imannms000\LaravelUnifiedSubscriptions\Models\Subscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Routing\Controller;
use Exception;
use Imannms000\LaravelUnifiedSubscriptions\Enums\Gateway;
use Imannms000\LaravelUnifiedSubscriptions\Http\Requests\VerifyGoogleSubscriptionRequest;

class GoogleSubscriptionController extends Controller
{
    public function verify(VerifyGoogleSubscriptionRequest $request, GooglePlayGateway $gateway): JsonResponse
    {
        $user = $request->user();
        $planId = $request->validated('plan_id');
        $purchaseToken = $request->validated('purchase_token');

        $plan = Plan::findOrFail($planId);

        try {
            return DB::transaction(function () use ($user, $plan, $purchaseToken, $gateway) {
                // Prevent duplicate active subscription on same plan + gateway
                $existing = $user->subscriptions()
                    ->where('plan_id', $plan->id)
                    ->where('gateway', Gateway::GOOGLE->value)
                    ->where(function ($q) {
                        $q->whereNull('canceled_at')
                          ->orWhereNull('ends_at')
                          ->orWhere('ends_at', '>', now());
                    })
                    ->first();

                if ($existing) {
                    // Optional: re-validate existing token if needed
                    // Or just return success
                    return response()->json([
                        'status' => 'already_subscribed',
                        'message' => 'User already has an active subscription to this plan.',
                        'subscription_id' => $existing->id,
                    ]);
                }

                // Create new subscription record
                $subscription = $user->subscriptions()->create([
                    'plan_id' => $plan->id,
                    'gateway' => Gateway::GOOGLE->value,
                    // gateway_id will be set inside gateway->createSubscription()
                ]);

                // Validate receipt with Google and update subscription
                $gateway->createSubscription($subscription, [
                    'purchase_token' => $purchaseToken,
                ]);

                Log::info('Google Play subscription verified successfully', [
                    'user_id' => $user->id,
                    'subscription_id' => $subscription->id,
                    'plan_id' => $plan->id,
                ]);

                return response()->json([
                    'status' => 'success',
                    'message' => 'Subscription activated successfully.',
                    'subscription_id' => $subscription->id,
                    'ends_at' => $subscription->ends_at?->toISOString(),
                    'is_active' => $subscription->isActive(),
                ]);
            });
        } catch (Exception $e) {
            Log::error('Google Play subscription verification failed', [
                'user_id' => $user->id,
                'plan_id' => $plan->id,
                'purchase_token' => $purchaseToken,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to verify subscription. Please try again.',
                // In production, hide details
                // 'debug' => $e->getMessage(),
            ], 400);
        }
    }
}