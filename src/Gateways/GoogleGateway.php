<?php

namespace Imannms000\LaravelUnifiedSubscriptions\Gateways;

use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Google\Client as GoogleClient;
use Google\Service\AndroidPublisher;
use Imannms000\LaravelUnifiedSubscriptions\Contracts\GatewayInterface;
use Imannms000\LaravelUnifiedSubscriptions\Models\Subscription;
use Carbon\Carbon;
use Exception;
use Google\Service\AndroidPublisher\CancelSubscriptionPurchaseRequest;
use Imannms000\LaravelUnifiedSubscriptions\Enums\Gateway;
use Imannms000\LaravelUnifiedSubscriptions\Models\Plan;

class GoogleGateway extends AbstractGateway implements GatewayInterface
{
    protected AndroidPublisher $service;

    protected string $packageName;

    protected string $userModel;

    public function __construct()
    {
        parent::__construct();

        $client = new GoogleClient();
        $client->setAuthConfig(config('subscription.gateways.google.service_account'));
        $client->addScope('https://www.googleapis.com/auth/androidpublisher');

        $this->service = new AndroidPublisher($client);
        $this->packageName = config('subscription.gateways.google.package_name');
        $this->userModel = config('subscription.models.user');
    }

    public function getName(): string
    {
        return Gateway::GOOGLE->value;
    }

    public function createSubscription(Subscription $subscription, array $options = []): mixed
    {
        $token = $options['purchase_token'] ?? null;

        if (! $token) {
            throw new Exception('Purchase token is required for Google Play subscription validation.');
        }

        // Fetch current subscription state
        $response = $this->service->purchases_subscriptionsv2->get(
            $this->packageName,
            $token
        );

        // Extract expiry from line items (safely navigate the structure)
        $lineItems = $response->lineItems ?? [];
        $expiryMs = 0;

        foreach ($lineItems as $lineItem) {
            $expiryDetails = $lineItem->expiryTime ?? null;
            if ($expiryDetails && $expiryDetails->seconds ?? null) {
                $currentMs = ($expiryDetails->seconds * 1000) + ($expiryDetails->nanos / 1000000 ?? 0);
                if ($currentMs > $expiryMs) {
                    $expiryMs = $currentMs;
                }
            }
        }

        $endsAt = $expiryMs ? Carbon::createFromTimestampMs($expiryMs) : null;

        // Mark subscription as active
        $this->markSubscriptionAsActive($subscription, $token, $endsAt, (array) $response->toSimpleObject());

        // Record initial transaction
        $price = $subscription->plan->getPriceForGateway(Gateway::GOOGLE->value);
        $currency = $subscription->plan->getCurrencyForGateway(Gateway::GOOGLE->value);

        $subscription->recordTransaction(
            type: 'payment',
            amount: $price,
            currency: $currency,
            gatewayTransactionId: $token,
            metadata: json_decode(json_encode($response), true) // Convert to array safely
        );

        return $response;
    }

    public function redirectToCheckout(Subscription $subscription): ?RedirectResponse
    {
        return null; // Handled client-side in the app
    }

    public function cancelSubscription(Subscription $subscription): void
    {
        // Create request object (empty body is allowed – defaults to DEVELOPER_REQUESTED_STOP_PAYMENTS)
        $cancelRequest = new CancelSubscriptionPurchaseRequest();

        // Optional: explicitly set cancellation type if needed
        // $cancelRequest->setCancellationType('DEVELOPER_REQUESTED_STOP_PAYMENTS');

        $this->service->purchases_subscriptionsv2->cancel(
            $this->packageName,
            $subscription->gateway_id,
            $cancelRequest
        );

        // Local state: mark as canceled, access remains until expiry
        $subscription->cancel(immediately: false);

        $this->markSubscriptionAsCanceled($subscription);
    }

    public function resumeSubscription(Subscription $subscription): void
    {
        // Google Play does not support server-side resume for canceled subscriptions
        // User must repurchase or manage via Play Store
        throw new Exception('Server-side resume not supported for Google Play subscriptions. User must repurchase.');
    }

    public function swapPlan(Subscription $subscription, $newPlanId): void
    {
        // Plan changes must be handled client-side via new purchase (upgrade/downgrade)
        throw new Exception('Plan swap must be initiated from the client app on Google Play.');
    }

    public function handleWebhook(Request $request): void
    {
        $payload = $request->json()->all();

        // Handle Pub/Sub format (message.data base64-encoded) or test notifications
        if (isset($payload['message']['data'])) {
            $data = json_decode(base64_decode($payload['message']['data']), true);
        } else {
            $data = $payload;
        }

        $subscriptionNotification = $data['subscriptionNotification'] ?? null;

        if (! $subscriptionNotification) {
            \Log::debug('Google webhook: missing subscription notification', [
                'subscriptionNotification' => $subscriptionNotification,
                'payload' => $payload
            ]);
            return;
        }

        $token = $subscriptionNotification['purchaseToken'] ?? null;
        $obfuscatedId = $subscriptionNotification['obfuscatedExternalAccountId'] ?? null;
        $type = (int) ($subscriptionNotification['notificationType'] ?? 0);

        if (! $token) {
            \Log::debug('Google webhook: missing token', [
                'token' => $token,
                'type' => $type,
                'payload' => $payload
            ]);
            return;
        }

        // Always fetch latest state from Google for accuracy
        try {
            $response = $this->service->purchases_subscriptionsv2->get($this->packageName, $token);

            $basePlanId = $response->lineItems[0]->offerDetails->basePlanId ?? null;
            $offerId    = $response->lineItems[0]->offerDetails->offerId ?? null;
            $productId  = $response->lineItems[0]->productId ?? null;

            $lineItems = $response->lineItems ?? [];
            $expiryMs = 0;

            foreach ($lineItems as $lineItem) {
                $expiryDetails = $lineItem->expiryTime ?? null;
                if ($expiryDetails && $expiryDetails->seconds ?? null) {
                    $currentMs = ($expiryDetails->seconds * 1000) + ($expiryDetails->nanos / 1000000 ?? 0);
                    if ($currentMs > $expiryMs) {
                        $expiryMs = $currentMs;
                    }
                }
            }

            $endsAt = $expiryMs ? Carbon::createFromTimestampMs($expiryMs) : null;
        } catch (Exception $e) {
            // Fallback to notification type if API call fails
            $endsAt = null;
        }

        $subscription = Subscription::where('gateway', Gateway::GOOGLE->value)
            ->where('gateway_id', $token)
            ->first();

        // Attach user if subscription already exists but has no user
        if ($subscription && $obfuscatedId && !$subscription->subscribable_id) {
            $user = $this->userModel::findByGoogleObfuscatedId($obfuscatedId);
            if ($user) {
                $subscription->update([
                    'subscribable_id' => $user->id,
                    'subscribable_type' => $user->getMorphClass(),
                ]);
            }
        }

        // If not found, try to create one using obfuscatedAccountId
        if (! $subscription && $obfuscatedId) {
            $user = $this->userModel::findByGoogleObfuscatedId($obfuscatedId); // using the trait method

            if ($user) {
                $plan = Plan::whereHas('gatewayPrices', function ($q) use ($basePlanId) {
                    $q->where('gateway', Gateway::GOOGLE->value)
                    ->where('gateway_plan_id', $basePlanId);
                })->first();

                if (! $plan) {
                    \Log::warning('No plan matched basePlanId from Google RTDN', [
                        'basePlanId' => $basePlanId,
                        'obfuscated_id' => $obfuscatedId,
                        'token' => $token,
                    ]);
                    return;
                }

                $subscription = $user->subscriptions()->create([
                    'plan_id' => $plan->id,
                    'gateway' => Gateway::GOOGLE->value,
                    'gateway_id' => $token,
                    'starts_at' => now(),
                    // Add other defaults (trial_ends_at, etc.) as needed
                ]);

                \Log::info('Created new subscription from Google RTDN using obfuscatedAccountId', [
                    'user_id' => $user->id,
                    'token' => $token,
                    'obfuscated_id' => $obfuscatedId,
                    'productId' => $productId,
                    'basePlanId' => $basePlanId,
                    'offerId' => $offerId,
                    'gateway_response' => (array) $response?->toSimpleObject(),
                ]);
            } else {
                \Log::warning('Google RTDN received with unknown obfuscatedAccountId', [
                    'obfuscated_id' => $obfuscatedId,
                    'token' => $token,
                    'type' => $type,
                    'gateway_response' => (array) $response?->toSimpleObject(),
                ]);
                return; // or create pending subscription if you want
            }
        }

        if (! $subscription) {
            // If still no subscription, wait for receipt
            \Log::debug('Google webhook: Subscription not found', [
                'token' => $token,
                'subscription' => $subscription,
                'payload' => $payload,
                'gateway_response' => (array) $response?->toSimpleObject(),
            ]);
            return;
        }

        // Handle key notification types
        match ($type) {
            3, 4  => $this->markSubscriptionAsRenewed($subscription, $endsAt), // SUBSCRIPTION_RENEWED
            7     => $this->markSubscriptionAsCanceled($subscription),        // SUBSCRIPTION_CANCELED
            8     => $this->markSubscriptionAsCanceled($subscription),        // SUBSCRIPTION_EXPIRED
            default => null,
        };

        // Sync local ends_at if fresh data available
        if (isset($response)) {
            $subscription->update([
                'gateway_response' => (array) $response->toSimpleObject(),
                'ends_at' => $endsAt
            ]);
        }

        \Log::debug('Google Play subscription updated', [
            'subscription_id' => $subscription->id,
            'token' => $token,
            'ends_at' => $endsAt,
            'payload' => $payload,
            'gateway_response' => (array) $response?->toSimpleObject(),
        ]);
    }
}