<?php

namespace Imannms000\LaravelUnifiedSubscriptions\Gateways;

use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Imannms000\LaravelUnifiedSubscriptions\Contracts\GatewayInterface;
use Imannms000\LaravelUnifiedSubscriptions\Models\Subscription;
use Imannms000\LaravelUnifiedSubscriptions\Models\Plan;
use Srmklive\PayPal\Services\PayPal as PayPalClient;
use Carbon\Carbon;
use Exception;
use Imannms000\LaravelUnifiedSubscriptions\Enums\Gateway;

class PayPalGateway extends AbstractGateway implements GatewayInterface
{
    protected PayPalClient $client;

    public function __construct()
    {
        parent::__construct();

        $this->client = app(PayPalClient::class);
        $this->client->setApiCredentials(config('subscription.gateways.paypal'));
        $this->client->getAccessToken(); // Refreshes token if needed
    }

    public function createSubscription(Subscription $subscription, array $options = []): mixed
    {
        $plan = $subscription->plan;
        $subscribable = $subscription->subscribable;

        if (! $plan->gateway_id) {
            throw new Exception('Plan must have a gateway_id synced with PayPal.');
        }

        $payload = [
            'plan_id' => $plan->gateway_id,
            'subscriber' => [
                'name' => [
                    'given_name' => $subscribable->name ?? 'Customer',
                    'surname'     => $subscribable->lastname ?? 'User',
                ],
                'email_address' => $subscribable->email,
            ],
            'application_context' => [
                'brand_name'          => config('app.name'),
                'locale'              => 'en-US',
                'shipping_preference' => 'NO_SHIPPING',
                'user_action'         => 'SUBSCRIBE_NOW',
                'return_url'          => config('subscription.gateways.paypal.return_url'),
                'cancel_url'          => config('subscription.gateways.paypal.cancel_url'),
            ],
        ];

        // Optional: add custom_id for easier identification
        if (config('subscription.gateways.paypal.use_custom_id', true)) {
            $payload['custom_id'] = 'sub-' . $subscription->id;
        }

        $response = $this->client->createSubscription($payload);

        if (isset($response['id']) && isset($response['status']) && $response['status'] === 'APPROVAL_PENDING') {
            $subscription->update(['gateway_id' => $response['id']]);
            return $response;
        }

        throw new Exception('PayPal subscription creation failed: ' . json_encode($response));
    }

    public function redirectToCheckout(Subscription $subscription): ?RedirectResponse
    {
        $response = $this->createSubscription($subscription);

        foreach ($response['links'] ?? [] as $link) {
            if ($link['rel'] === 'approve') {
                return redirect()->away($link['href']);
            }
        }

        throw new Exception('Approval link not found in PayPal response.');
    }

    public function cancelSubscription(Subscription $subscription): void
    {
        $this->client->cancelSubscription($subscription->gateway_id, 'Customer requested cancellation');

        $this->markSubscriptionAsCanceled($subscription);
    }

    public function resumeSubscription(Subscription $subscription): void
    {
        // PayPal REST API does not support direct reactivation of canceled subscriptions.
        // Canceled subscriptions cannot be reactivated; a new one must be created.
        // If the subscription was only suspended (via suspend API), use activateSubscription.
        throw new Exception('Cannot reactivate a canceled PayPal subscription. Create a new subscription instead.');
    }

    public function swapPlan(Subscription $subscription, $newPlanId): void
    {
        // Find new plan by gateway_id
        $newPlan = Plan::where('gateway', Gateway::PAYPAL->value)
            ->where('gateway_id', $newPlanId)
            ->firstOrFail();

        $response = $this->client->reviseSubscription($subscription->gateway_id, [
            'plan_id' => $newPlanId,
        ]);

        if (! isset($response['links'])) {
            throw new Exception('Failed to revise PayPal subscription.');
        }

        // Local swap after successful revise (user will need to approve if required)
        $subscription->swap($newPlan);

        // Note: If PayPal requires re-approval, handle via return_url webhook or polling
    }

    public function handleWebhook(Request $request): void
    {
        $payload = $request->json()->all();

        if (empty($payload)) {
            return;
        }

        $eventType = $payload['event_type'] ?? null;

        $subscriptionId = $payload['resource']['id'] ?? null;

        if (! $subscriptionId) {
            return;
        }

        $subscription = Subscription::where('gateway', Gateway::PAYPAL->value)
            ->where('gateway_id', $subscriptionId)
            ->first();

        if (! $subscription) {
            return;
        }

        match ($eventType) {
            'BILLING.SUBSCRIPTION.ACTIVATED',
            'BILLING.SUBSCRIPTION.CREATED' => $this->handleSubscriptionActivated($subscription, $payload),

            'BILLING.SUBSCRIPTION.CANCELLED',
            'BILLING.SUBSCRIPTION.EXPIRED' => $this->markSubscriptionAsCanceled($subscription),

            'BILLING.SUBSCRIPTION.RE-ACTIVATED' => $subscription->resume(),

            'PAYMENT.SALE.COMPLETED' => $this->handlePaymentCompleted($subscription, $payload),

            'BILLING.SUBSCRIPTION.UPDATED' => $this->handleSubscriptionUpdated($subscription, $payload),

            default => null,
        };
    }

    protected function handleSubscriptionActivated(Subscription $subscription, array $payload): void
    {
        $endsAt = null;
        // PayPal doesn't always provide next_billing_time on activation
        // You may fetch subscription details if needed: $this->client->showSubscriptionDetails($subscription->gateway_id)

        $this->markSubscriptionAsActive($subscription, $subscription->gateway_id, $endsAt);

        // Record initial payment if any
        $price = $subscription->plan->getPriceForGateway(Gateway::PAYPAL->value);
        $currency = $subscription->plan->getCurrencyForGateway(Gateway::PAYPAL->value);

        $subscription->recordTransaction(
            type: 'payment',
            amount: $price,
            currency: $currency,
            gatewayTransactionId: $payload['id'] ?? null,
            metadata: $payload
        );
    }

    protected function handlePaymentCompleted(Subscription $subscription, array $payload): void
    {
        $amount = $payload['resource']['amount']['total'] ?? $subscription->plan->getPriceForGateway(Gateway::PAYPAL->value);
        $currency = $payload['resource']['amount']['currency'] ?? $subscription->plan->getCurrencyForGateway(Gateway::PAYPAL->value);

        $subscription->recordTransaction(
            type: 'renewal',
            amount: $amount,
            currency: $currency,
            gatewayTransactionId: $payload['resource']['id'] ?? null,
            metadata: $payload
        );

        $this->markSubscriptionAsRenewed($subscription, null);
    }

    protected function handleSubscriptionUpdated(Subscription $subscription, array $payload): void
    {
        // Handle plan changes, quantity updates, etc.
        // Optionally sync ends_at or other fields
    }

    /**
     * Optional: Implement webhook signature verification
     */
    public function verifyWebhookSignature(Request $request): bool
    {
        $transmissionId = $request->header('PAYPAL-TRANSMISSION-ID');
        $transmissionTime = $request->header('PAYPAL-TRANSMISSION-TIME');
        $certUrl = $request->header('PAYPAL-CERT-URL');
        $authAlgo = $request->header('PAYPAL-AUTH-ALGO');
        $transmissionSig = $request->header('PAYPAL-TRANSMISSION-SIG');
        $webhookId = config('subscription.gateways.paypal.webhook_id');

        // Required headers check
        if (
            empty($transmissionId) ||
            empty($transmissionTime) ||
            empty($certUrl) ||
            empty($authAlgo) ||
            empty($transmissionSig) ||
            empty($webhookId)
        ) {
            return false;
        }

        $verificationData = [
            'auth_algo'         => $authAlgo,
            'cert_url'          => $certUrl,
            'transmission_id'   => $transmissionId,
            'transmission_sig'  => $transmissionSig,
            'transmission_time' => $transmissionTime,
            'webhook_id'        => $webhookId,
            'webhook_event'     => $request->json()->all(), // Use JSON-decoded payload
        ];

        $response = $this->client->verifyWebHook($verificationData);

        // The method returns an array with 'verification_status' => 'SUCCESS' or 'FAILURE'
        return isset($response['verification_status']) &&
               strtoupper($response['verification_status']) === 'SUCCESS';
    }
}