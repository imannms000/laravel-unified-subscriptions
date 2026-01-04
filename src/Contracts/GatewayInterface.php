<?php

namespace Imannms000\LaravelUnifiedSubscriptions\Contracts;

use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Imannms000\LaravelUnifiedSubscriptions\Models\Plan;
use Imannms000\LaravelUnifiedSubscriptions\Models\Subscription;

interface GatewayInterface
{
    /**
     * Create a new subscription on the gateway.
     * Return the gateway's subscription ID or object.
     */
    public function createSubscription(Subscription $subscription, array $options = []): mixed;

    /**
     * Cancel the subscription on the gateway.
     */
    public function cancelSubscription(Subscription $subscription): void;

    /**
     * Resume a canceled subscription.
     */
    public function resumeSubscription(Subscription $subscription): void;

    /**
     * Swap to a new plan.
     */
    public function swapPlan(Subscription $subscription, Plan|int|string $newPlanId): void;

    /**
     * Handle incoming webhook from the gateway.
     */
    public function handleWebhook(Request $request): void;

    /**
     * Redirect user to gateway checkout (for web-based gateways like PayPal/Xendit).
     * Return null for in-app gateways (Google/Apple).
     */
    public function redirectToCheckout(Subscription $subscription): ?RedirectResponse;
}