<?php

namespace Imannms000\LaravelUnifiedSubscriptions\Commands;

use Illuminate\Console\Command;
use Imannms000\LaravelUnifiedSubscriptions\Gateways\FakeGateway;
use Imannms000\LaravelUnifiedSubscriptions\Models\Plan;
use Imannms000\LaravelUnifiedSubscriptions\Models\Subscription;

class FakeSubscriptionStatusCommand extends Command
{
    protected $signature = 'subscription:fake:status {subscriptionId : The ID of the fake subscription}';

    protected $description = 'Show detailed status and timeline of a fake subscription';

    public function handle(): int
    {
        $subscription = Subscription::with(['plan', 'transactions'])->find($this->argument('subscriptionId'));

        if (!$subscription) {
            $this->error('Subscription not found.');
            return self::FAILURE;
        }

        if ($subscription->gateway !== 'fake') {
            $this->error('This command only works on fake gateway subscriptions.');
            return self::FAILURE;
        }

        $plan = $subscription->plan;
        $config = config('subscription.fake.auto_renew');

        $this->newLine();
        $this->info("Fake Subscription Status");
        $this->line(" <fg=cyan>ID:</> {$subscription->id}");
        $this->line(" <fg=cyan>User ID:</> {$subscription->subscribable_id}");
        $this->line(" <fg=cyan>Plan:</> {$plan->name} ({$plan->slug})");
        $this->line(" <fg=cyan>Price:</> " . number_format($plan->price, 2) . " {$plan->currency}");

        // Current status
        $status = $subscription->isActive() 
            ? '<fg=green>Active</>' 
            : ($subscription->canceled_at ? '<fg=red>Canceled</>' : '<fg=yellow>Expired</>');

        $this->line(" <fg=cyan>Status:</> {$status}");

        // Dates
        $this->line(" <fg=cyan>Starts At:</> " . ($subscription->starts_at?->format('Y-m-d H:i:s') ?? '<fg=gray>Immediate</>'));
        $this->line(" <fg=cyan>Ends At:</> " . ($subscription->ends_at?->format('Y-m-d H:i:s') ?? '<fg=gray>Never</>'));

        if ($subscription->ends_at) {
            $remaining = now()->diffForHumans($subscription->ends_at, ['parts' => 2, 'short' => true]);
            $color = $subscription->ends_at->isFuture() ? 'green' : 'red';
            $this->line(" <fg=cyan>Time Remaining:</> <fg={$color}>{$remaining}</>");
        }

        // Renewal tracking
        $currentCount = $subscription->renewal_count;
        $max = $config['max_renewals'] ?? 5;

        $renewalProgress = $currentCount . '/' . $max;
        $renewalColor = $currentCount >= $max ? 'red' : 'yellow';

        $this->line(" <fg=cyan>Renewals:</> <fg={$renewalColor}>{$renewalProgress}</> (max: {$max})");

        if ($subscription->canceled_at) {
            $this->line(" <fg=cyan>Canceled At:</> " . $subscription->canceled_at->format('Y-m-d H:i:s'));
        }

        // Auto-renew config
        $this->newLine();
        $this->info("Fake Gateway Auto-Renew Config");
        $this->table(
            ['Setting', 'Value'],
            [
                ['Enabled', config('subscription.fake.auto_renew.enabled') ? '<fg=green>Yes</>' : '<fg=red>No</>'],
                ['Interval', config('subscription.fake.auto_renew.interval') . ' ' . config('subscription.fake.auto_renew.unit')],
                ['Max Renewals', $max],
            ]
        );

        // Recent transactions
        if ($subscription->transactions->isNotEmpty()) {
            $this->newLine();
            $this->info("Recent Transactions (latest 5)");

            $rows = $subscription->transactions
                ->sortByDesc('created_at')
                ->take(5)
                ->map(function ($txn) {
                    $typeColor = match($txn->type) {
                        'payment' => 'green',
                        'renewal' => 'blue',
                        'expiry' => 'yellow',
                        default => 'white',
                    };

                    return [
                        $txn->created_at->format('M d H:i'),
                        "<fg={$typeColor}>{$txn->type}</>",
                        number_format($txn->amount, 2) . " {$txn->currency}",
                        $txn->gateway_transaction_id,
                    ];
                })->toArray();

            $this->table(['Date', 'Type', 'Amount', 'Txn ID'], $rows);
        }

        $this->newLine();
        $this->info("Next possible auto-renew: " . 
            ($subscription->ends_at && $subscription->ends_at->isFuture()
                ? $subscription->ends_at->diffForHumans(now(), ['parts' => 2])
                : '<fg=red>Never (expired/canceled)</>'
            )
        );

        return self::SUCCESS;
    }
}