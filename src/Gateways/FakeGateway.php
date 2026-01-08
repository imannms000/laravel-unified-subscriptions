<?php

namespace Imannms000\LaravelUnifiedSubscriptions\Gateways;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Imannms000\LaravelUnifiedSubscriptions\Contracts\GatewayInterface;
use Imannms000\LaravelUnifiedSubscriptions\Models\Subscription;
use Carbon\Carbon;
use Exception;

class FakeGateway extends AbstractGateway implements GatewayInterface
{
    public function __construct()
    {
        parent::__construct();

        if (!config('subscription.fake.enabled')) {
            throw new Exception('Fake gateway is disabled in this environment ['.app()->environment().'].');
        }
    }

    public function createSubscription(Subscription $subscription, array $options = []): mixed
    {
        // Instant activation
        $plan = $subscription->plan;

        $unit = config('subscription.fake.auto_renew.unit', 'minutes');
        $interval = config('subscription.fake.auto_renew.interval', 5);

        $endsAt = now()->add($unit, $interval);

        $this->markSubscriptionAsActive(
            subscription: $subscription,
            gatewayId: 'fake-sub-' . $subscription->id,
            endsAt: $endsAt
        );

        // Reset renewal count
        $subscription->resetRenewalCount();

        // Record initial "payment"
        $price = $plan->getPriceForGateway('fake');
        $currency = $plan->getCurrencyForGateway('fake');

        $subscription->recordTransaction(
            type: 'payment',
            amount: $price,
            currency: $currency,
            gatewayTransactionId: 'fake-txn-' . $subscription->id . '-initial',
            metadata: ['note' => 'Fake gateway initial activation']
        );

        \Log::info("[FakeGateway] Subscription {$subscription->id} activated instantly.");

        return ['status' => 'active', 'message' => 'Fake subscription activated'];
    }

    public function redirectToCheckout(Subscription $subscription): ?RedirectResponse
    {
        // No redirect needed
        return null;
    }

    public function cancelSubscription(Subscription $subscription): void
    {
        $this->markSubscriptionAsCanceled($subscription);
        \Log::info("[FakeGateway] Subscription {$subscription->id} canceled manually.");
    }

    public function resumeSubscription(Subscription $subscription): void
    {
        $subscription->resume();
        \Log::info("[FakeGateway] Subscription {$subscription->id} resumed.");
    }

    public function swapPlan(Subscription $subscription, $newPlanId): void
    {
        $newPlan = $subscription->plan->where('id', $newPlanId)->firstOrFail();
        $subscription->swap($newPlan);
        \Log::info("[FakeGateway] Subscription {$subscription->id} swapped to plan {$newPlan->name}.");
    }

    public function handleWebhook(Request $request): void
    {
        // No webhook needed — all actions are direct
        \Log::info("[FakeGateway] Webhook ignored (not used).");
    }

    /**
     * Manually trigger a renewal (used by Artisan command and scheduler)
     */
    public function renewSubscription(Subscription $subscription): void
    {
        $max = config('subscription.fake.auto_renew.max_renewals', 999);

        if ($subscription->renewal_count >= $max) {
            $this->markSubscriptionAsCanceled($subscription);
            \Log::info("[FakeGateway] Subscription {$subscription->id} auto-canceled: max renewals ({$max}) reached.");
            return;
        }

        // Use FAKE config interval, NOT the real plan interval
        $unit = config('subscription.fake.auto_renew.unit', 'minutes');
        $intervalCount = config('subscription.fake.auto_renew.interval', 5);

        $from = $subscription->ends_at ?? now();
        $nextEndsAt = $from->add($unit, $intervalCount);

        $this->markSubscriptionAsRenewed($subscription, $nextEndsAt);

        // Record transaction
        $price = $subscription->plan->getPriceForGateway('fake');
        $currency = $subscription->plan->getCurrencyForGateway('fake');

        $subscription->recordTransaction(
            type: 'renewal',
            amount: $price,
            currency: $currency,
            gatewayTransactionId: 'fake-txn-' . $subscription->id . '-renew-' . $subscription->renewal_count,
            metadata: ['note' => 'Fake gateway auto-renewal (fast testing mode)']
        );

        \Log::info("[FakeGateway] Subscription {$subscription->id} renewed (count: {$subscription->renewal_count}/{$max}, next ends: {$nextEndsAt})");
    }
}