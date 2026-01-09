<?php

namespace Imannms000\LaravelUnifiedSubscriptions\Commands;

use Illuminate\Console\Command;
use Imannms000\LaravelUnifiedSubscriptions\Gateways\FakeGateway;
use Imannms000\LaravelUnifiedSubscriptions\Models\Plan;
use Imannms000\LaravelUnifiedSubscriptions\Models\Subscription;

class FakeExpireSubscriptionCommand extends Command
{
    protected $signature = 'subscription:fake:expire 
                            {subscriptionId : The ID of the fake subscription to expire}
                            {--force : Bypass safety checks and force immediate expiry}';

    protected $description = 'Manually expire a fake subscription (set ends_at to past)';

    public function handle(): int
    {
        $subscription = Subscription::find($this->argument('subscriptionId'));

        if (!$subscription) {
            $this->error('Subscription not found.');
            return self::FAILURE;
        }

        if ($subscription->gateway->value !== 'fake') {
            $this->error('This command only works on fake gateway subscriptions.');
            return self::FAILURE;
        }

        if ($subscription->canceled_at) {
            $this->warn("Subscription {$subscription->id} is already canceled.");
            return self::SUCCESS;
        }

        // Safety check: warn if already expired
        if ($subscription->ends_at && $subscription->ends_at->isPast()) {
            $this->info("Subscription {$subscription->id} is already expired.");
            if (!$this->option('force')) {
                return self::SUCCESS;
            }
        }

        // Set ends_at to 1 minute ago (definitely past)
        $expiredAt = now()->subMinute();

        $subscription->update([
            'ends_at' => $expiredAt,
        ]);

        // Optional: record a fake "expiry" transaction for audit
        $price = $subscription->plan->getPriceForGateway('fake');
        $currency = $subscription->plan->getCurrencyForGateway('fake');

        $subscription->recordTransaction(
            type: 'expiry',
            amount: 0,
            currency: $currency,
            gatewayTransactionId: 'fake-expiry-' . $subscription->id . '-' . now()->timestamp,
            status: 'completed',
            metadata: [
                'note' => 'Fake gateway manual expiry triggered via Artisan',
                'expired_at' => $expiredAt->toDateTimeString(),
            ]
        );

        \Log::info("[FakeGateway] Subscription {$subscription->id} manually expired (ends_at set to {$expiredAt})");

        $this->info("Fake subscription <fg=cyan>{$subscription->id}</> has been <fg=yellow>expired</> (ends_at = {$expiredAt})");

        $this->line(" → Access should now be restricted.");
        $this->line(" → If grace period is configured, it will start now.");
        $this->line(" → Auto-renew scheduler will skip this subscription.");

        return self::SUCCESS;
    }
}