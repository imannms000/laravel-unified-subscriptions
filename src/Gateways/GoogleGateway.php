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

class GoogleGateway extends AbstractGateway implements GatewayInterface
{
    protected AndroidPublisher $service;

    protected string $packageName;

    public function __construct()
    {
        parent::__construct();

        $client = new GoogleClient();
        $client->setAuthConfig(config('subscription.gateways.google.service_account'));
        $client->addScope('https://www.googleapis.com/auth/androidpublisher');

        $this->service = new AndroidPublisher($client);
        $this->packageName = config('subscription.gateways.google.package_name');
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
            \Log::debug('Google Play subscription', [
                'subscriptionNotification' => $subscriptionNotification,
                'payload' => $payload
            ]);
            return;
        }

        $token = $subscriptionNotification['purchaseToken'] ?? null;
        $type = (int) ($subscriptionNotification['notificationType'] ?? 0);

        if (! $token) {
            \Log::debug('Google Play subscription', [
                'token' => $token,
                'type' => $type,
                'payload' => $payload
            ]);
            return;
        }

        $subscription = Subscription::where('gateway', Gateway::GOOGLE->value)
            ->where('gateway_id', $token)
            ->first();

        if (! $subscription) {
            \Log::debug('Google Play subscription', [
                'token' => $token,
                'subscription' => $subscription,
                'payload' => $payload
            ]);
            return;
        }

        // Always fetch latest state from Google for accuracy
        try {
            $response = $this->service->purchases_subscriptionsv2->get($this->packageName, $token);

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
            'gateway_response' => (array) $response->toSimpleObject(),
        ]);
    }
}