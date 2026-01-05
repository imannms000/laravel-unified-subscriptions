<?php

namespace Imannms000\LaravelUnifiedSubscriptions\Http\Controllers;

use Imannms000\LaravelUnifiedSubscriptions\Gateways\AppleGateway;
use Imannms000\LaravelUnifiedSubscriptions\Models\Subscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Exception;
use Illuminate\Routing\Controller;
use Imannms000\LaravelUnifiedSubscriptions\Enums\Gateway;
use Imannms000\LaravelUnifiedSubscriptions\Http\Requests\VerifyAppleSubscriptionRequest;

class AppleSubscriptionController extends Controller
{
    public function __construct(protected AppleGateway $appleGateway)
    {
    }

    /**
     * Verify Apple receipt and create/activate subscription
     */
    public function verify(VerifyAppleSubscriptionRequest $request): JsonResponse
    {
        $user = $request->user();
        $planId = $request->validated('plan_id');
        $receiptData = $request->validated('receipt_data');

        return DB::transaction(function () use ($user, $planId, $receiptData) {
            // Create pending subscription record
            $subscription = $user->subscriptions()->create([
                'plan_id' => $planId,
                'gateway' => Gateway::APPLE->value,
                // gateway_id and dates will be filled by gateway
            ]);

            try {
                // Verify receipt with Apple and update subscription
                $this->appleGateway->createSubscription($subscription, [
                    'receipt_data' => $receiptData,
                ]);

                // Reload with fresh data
                $subscription->load('plan', 'transactions');

                return response()->json([
                    'status' => 'success',
                    'message' => 'Subscription activated successfully',
                    'subscription' => $subscription,
                ], 200);
            } catch (Exception $e) {
                // Clean up failed subscription
                $subscription->delete();

                \Log::error('Apple receipt verification failed', [
                    'user_id' => $user->id,
                    'plan_id' => $planId,
                    'error' => $e->getMessage(),
                ]);

                return response()->json([
                    'status' => 'error',
                    'message' => 'Invalid or expired receipt',
                    'error' => config('app.debug') ? $e->getMessage() : 'Verification failed',
                ], 400);
            }
        });
    }
}