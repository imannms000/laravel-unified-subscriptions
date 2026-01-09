<?php

namespace Imannms000\LaravelUnifiedSubscriptions\Gateways;

use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Imannms000\LaravelUnifiedSubscriptions\Contracts\GatewayInterface;
use Imannms000\LaravelUnifiedSubscriptions\Models\Subscription;
use Carbon\Carbon;
use Exception;
use Imannms000\LaravelUnifiedSubscriptions\Enums\Gateway;
use Imannms000\LaravelUnifiedSubscriptions\Models\Plan;

class XenditGateway extends AbstractGateway implements GatewayInterface
{
    protected string $baseUrl = 'https://api.xendit.co';

    protected string $secretKey;

    public function __construct()
    {
        parent::__construct();

        $this->secretKey = config('subscription.gateways.xendit.secret_key');

        if (!$this->secretKey) {
            throw new Exception('Xendit secret key is not configured.');
        }
    }

    public function getName(): string
    {
        return Gateway::XENDIT->value;
    }

    protected function apiRequest(string $method, string $endpoint, array $data = [], ?string $idempotencyKey = null): array
    {
        $response = Http::withBasicAuth($this->secretKey, '')
            ->withHeaders([
                'Content-Type' => 'application/json',
                'api-version' => '2022-07-31',
                'idempotency-key' => $idempotencyKey ?? ('xendit-sub-' . uniqid('', true)),
            ])
            ->$method($this->baseUrl . $endpoint, $data);

        if ($response->failed()) {
            Log::error('Xendit API request failed', [
                'method' => $method,
                'endpoint' => $endpoint,
                'data' => $data,
                'response' => $response->body(),
                'status' => $response->status(),
            ]);

            $errorMessage = $response->json('message') ?? $response->body() ?? 'Unknown error';

            throw new Exception("Xendit API error [{$response->status()}]: {$errorMessage}");
        }

        return $response->json();
    }

    public function createSubscription(Subscription $subscription, array $options = []): mixed
    {
        $plan = $subscription->plan;
        $subscribable = $subscription->subscribable;

        $amount = $plan->getPriceForGateway(Gateway::XENDIT->value);
        $currency = $plan->getCurrencyForGateway(Gateway::XENDIT->value);

        $payload = [
            'reference_id' => 'sub-' . $subscription->id,
            'customer_id' => null, // Optional - create customer separately if needed
            'recurring_action' => 'PAYMENT',
            'amount' => $amount,
            'currency' => $currency,
            'schedule' => [
                'reference_id' => 'sched-' . $subscription->id,
                'interval' => strtoupper($plan->interval->value),
                'interval_count' => $plan->interval_count ?? 1,
                'total_recurrence' => null, // Indefinite
                'anchor_date' => null, // Start immediately after activation
                'retry_interval' => 'DAY',
                'retry_interval_count' => 3,
                'total_retry' => 9,
                'failed_attempt_notifications' => [1, 3, 9],
            ],
            'description' => $plan->name . ' Subscription',
            'success_return_url' => route('subscription.xendit.success'),
            'failure_return_url' => route('subscription.xendit.cancel'),
            'notification_config' => [
                'recurring_created' => ['EMAIL'],
                'recurring_succeeded' => ['EMAIL'],
                'recurring_failed' => ['EMAIL'],
                'locale' => 'en',
            ],
            'failed_cycle_action' => 'STOP',
            'immediate_action_type' => 'FULL_AMOUNT',
        ];

        // No payment_methods - use hosted linking flow

        try {
            $response = $this->apiRequest('post', '/recurring/plans', $payload, $this->generateIdempotencyKey('create', $subscription));

            $subscription->update([
                'gateway_id' => $response['id'],
            ]);

            // Record setup
            $subscription->recordTransaction(
                type: 'setup',
                amount: $amount,
                currency: $currency,
                gatewayTransactionId: $response['id'],
                metadata: $response
            );

            return $response;
        } catch (Exception $e) {
            throw $e;
        }
    }

    public function redirectToCheckout(Subscription $subscription): ?RedirectResponse
    {
        $response = $this->createSubscription($subscription);

        $actions = $response['actions'] ?? [];

        foreach ($actions as $action) {
            if ($action['action'] === 'AUTH' && !empty($action['url'])) {
                return redirect()->away($action['url']);
            }
        }

        throw new Exception('No AUTH action URL found in Xendit response. Plan status: ' . ($response['status'] ?? 'unknown'));
    }

    public function getActionUrl(array $response): string
    {
        $actions = $response['actions'] ?? [];

        foreach ($actions as $action) {
            if ($action['action'] === 'AUTH' && !empty($action['url'])) {
                return $action['url'];
            }
        }

        throw new Exception('No AUTH action URL found in Xendit response. Plan status: ' . ($response['status'] ?? 'unknown'));
    }

    public function cancelSubscription(Subscription $subscription): void
    {
        try {
            $this->apiRequest('post', "/recurring/plans/{$subscription->gateway_id}/deactivate", [], $this->generateIdempotencyKey('cancel', $subscription));

            $this->markSubscriptionAsCanceled($subscription);
        } catch (Exception $e) {
            Log::error('Xendit inactivate failed', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    public function resumeSubscription(Subscription $subscription): void
    {
        // Deactivation (cancel) is permanent — sets status to INACTIVE, stops all cycles, no /activate or /resume endpoint.
        // No way to "resume" without relinking (hosted page required again).
        
        throw new Exception('Resume not supported on Xendit. Create a new subscription instead.');
    }

    /**
     * @param Subscription $subscription
     * @param Plan $newPlan
     */
    public function swapPlan(Subscription $subscription, $newPlan): void
    {
        $currentPlanDetails = $this->apiRequest('get', "/recurring/plans/{$subscription->gateway_id}");

        $updatesNeeded = false;

        // Prepare plan update payload if amount or currency changes
        $planPayload = [];
        if ($newPlan->getPriceForGateway(Gateway::XENDIT->value) !== $subscription->plan->getPriceForGateway(Gateway::XENDIT->value)) {
            $planPayload['amount'] = $newPlan->getPriceForGateway(Gateway::XENDIT->value);
            $updatesNeeded = true;
        }
        if ($newPlan->getCurrencyForGateway(Gateway::XENDIT->value) !== $subscription->plan->getCurrencyForGateway(Gateway::XENDIT->value)) {
            $planPayload['currency'] = $newPlan->getCurrencyForGateway(Gateway::XENDIT->value);
            $updatesNeeded = true;
        }
        $planPayload['description'] = $newPlan->name . ' Subscription'; // Always update description

        if (!empty($planPayload)) {
            $this->apiRequest('patch', "/recurring/plans/{$subscription->gateway_id}", $planPayload);
        }

        // Prepare schedule update if interval changes
        $schedulePayload = [];
        $scheduleId = $currentPlanDetails['schedule']['id'] ?? null; // From docs, schedule has id (resc-xxx)
        if ($scheduleId && (strtoupper($newPlan->interval->value) !== $currentPlanDetails['schedule']['interval'] || 
            ($newPlan->interval_count ?? 1) !== $currentPlanDetails['schedule']['interval_count'])) {
            $schedulePayload = [
                'interval' => strtoupper($newPlan->interval->value),
                'interval_count' => $newPlan->interval_count ?? 1,
                // Keep other fields or update as needed (e.g., anchor_date if changing)
            ];
            $this->apiRequest('patch', "/recurring/schedules/{$scheduleId}", $schedulePayload);
            $updatesNeeded = true;
        }

        if (!$updatesNeeded) {
            return; // No changes needed
        }

        // Local swap on success
        $subscription->swap($newPlan);

        // Record transaction for audit
        $subscription->recordTransaction(
            type: 'plan_swap',
            amount: $newPlan->getPriceForGateway(Gateway::XENDIT->value),
            currency: $newPlan->getCurrencyForGateway(Gateway::XENDIT->value),
            gatewayTransactionId: $subscription->gateway_id,
            metadata: ['old_plan_id' => $subscription->plan_id, 'new_plan_id' => $newPlan->id]
        );
    }

    public function handleWebhook(Request $request): void
    {
        $payload = $request->json()->all();

        // Verify callback token
        $callbackToken = config('subscription.gateways.xendit.callback_token');
        if ($callbackToken && $request->header('x-callback-token') !== $callbackToken) {
            Log::warning('Invalid Xendit webhook token');
            abort(401);
        }

        $event = $payload['event'] ?? null;

        // Use reference_id or id to find subscription
        $referenceId = $payload['data']['reference_id'] ?? null;
        $planId = $payload['data']['id'] ?? null;

        $subscription = null;

        if ($referenceId && str_starts_with($referenceId, 'sub-')) {
            preg_match('/sub-(\d+)/', $referenceId, $matches);
            $subscription = Subscription::find($matches[1] ?? null);
        }

        if (!$subscription && $planId) {
            $subscription = Subscription::where('gateway', Gateway::XENDIT->value)
                ->where('gateway_id', $planId)
                ->first();
        }

        if (!$subscription) {
            Log::info('Xendit webhook - subscription not found', ['payload' => $payload]);
            return;
        }

        Log::info('Xendit webhook processed', [
            'event' => $event,
            'subscription_id' => $subscription->id,
        ]);

        match ($event) {
            'recurring.plan.activated' => $this->handlePlanActivated($subscription, $payload),
            'recurring.plan.inactivated' => $this->markSubscriptionAsCanceled($subscription),
            'recurring.cycle.succeeded' => $this->handleCycleSucceeded($subscription, $payload),
            'recurring.cycle.failed' => $this->handleCycleFailed($subscription, $payload),
            'recurring.cycle.retrying' => $this->handleCycleRetrying($subscription, $payload),
            default => Log::debug('Unhandled Xendit webhook event', ['event' => $event]),
        };
    }

    protected function handlePlanActivated(Subscription $subscription, array $payload): void
    {
        $data = $payload['data'];

        $this->markSubscriptionAsActive($subscription, $data['id'] ?? $subscription->gateway_id, null, $payload);

        // First payment may be included on activation if immediate_action_type FULL_AMOUNT
        $amount = $data['amount'] ?? $subscription->plan->getPriceForGateway(Gateway::XENDIT->value);
        $currency = $data['currency'] ?? $subscription->plan->getCurrencyForGateway(Gateway::XENDIT->value);

        $subscription->recordTransaction(
            type: 'payment',
            amount: $amount,
            currency: $currency,
            gatewayTransactionId: $data['id'],
            metadata: $payload
        );
    }

    protected function handleCycleSucceeded(Subscription $subscription, array $payload): void
    {
        $data = $payload['data'];

        $amount = $data['amount'] ?? $subscription->plan->getPriceForGateway(Gateway::XENDIT->value);
        $currency = $data['currency'] ?? $subscription->plan->getCurrencyForGateway(Gateway::XENDIT->value);

        $subscription->recordTransaction(
            type: 'renewal',
            amount: $amount,
            currency: $currency,
            gatewayTransactionId: $data['cycle_id'] ?? $data['id'],
            metadata: $payload
        );

        $this->markSubscriptionAsRenewed($subscription, null, $payload);
    }

    protected function handleCycleFailed(Subscription $subscription, array $payload): void
    {
        $data = $payload['data'];

        $amount = $data['amount'] ?? $subscription->plan->getPriceForGateway(Gateway::XENDIT->value);
        $currency = $data['currency'] ?? $subscription->plan->getCurrencyForGateway(Gateway::XENDIT->value);

        $subscription->recordTransaction(
            type: 'renewal',
            amount: $amount,
            currency: $currency,
            gatewayTransactionId: $data['cycle_id'] ?? $data['id'],
            status: 'failed',
            metadata: $payload
        );

        // Optional: cancel subscription if failed_cycle_action = STOP
        if (($payload['data']['failed_cycle_action'] ?? null) === 'STOP') {
            $this->markSubscriptionAsCanceled($subscription);
        }
    }

    protected function handleCycleRetrying(Subscription $subscription, array $payload): void
    {
        // Log retry attempt, no state change needed
        Log::info('Xendit cycle retrying', ['payload' => $payload]);
    }

    protected function generateIdempotencyKey(string $action, Subscription $subscription): string
    {
        return "xendit-{$action}-sub-{$subscription->id}-" . substr(uniqid(), -6);
    }
}