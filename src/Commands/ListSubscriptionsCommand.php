<?php

namespace Imannms000\LaravelUnifiedSubscriptions\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Relations\Relation;
use Imannms000\LaravelUnifiedSubscriptions\Enums\Gateway;
use Imannms000\LaravelUnifiedSubscriptions\Gateways\FakeGateway;
use Imannms000\LaravelUnifiedSubscriptions\Models\Plan;
use Imannms000\LaravelUnifiedSubscriptions\Models\Subscription;

class ListSubscriptionsCommand extends Command
{
    protected $signature = 'subscription:list
                            {--user= : Filter by user ID}
                            {--gateway= : Filter by gateway (paypal, xendit, google_play, apple, fake)}
                            {--active : Show only active subscriptions}
                            {--canceled : Show only canceled subscriptions}
                            {--expired : Show only expired subscriptions}
                            {--fake : Show only fake gateway subscriptions}
                            {--sort=created_desc : Sort by: created_asc, created_desc, ends_asc, ends_desc}';

    protected $description = 'List all subscriptions with detailed status';

    public function handle(): int
    {
        $query = Subscription::with(['subscribable', 'plan', 'transactions']);

        // Filters
        if ($userId = $this->option('user')) {
            $query->where('subscribable_id', $userId)->where('subscribable_type', Relation::getMorphAlias(config('subscription.models.user')));
        }

        if ($gateway = $this->option('gateway')) {
            $query->where('gateway', strtolower($gateway));
        }

        if ($this->option('fake')) {
            $query->where('gateway', Gateway::FAKE->value);
        }

        if ($this->option('active')) {
            $query->where(function ($q) {
                $q->whereNull('canceled_at')
                  ->where(function ($qq) {
                      $qq->whereNull('ends_at')->orWhere('ends_at', '>', now());
                  });
            });
        }

        if ($this->option('canceled')) {
            $query->whereNotNull('canceled_at');
        }

        if ($this->option('expired')) {
            $query->whereNotNull('ends_at')
                  ->where('ends_at', '<=', now())
                  ->whereNull('canceled_at');
        }

        // Sorting
        $sort = $this->option('sort');
        match ($sort) {
            'created_asc' => $query->orderBy('created_at', 'asc'),
            'created_desc' => $query->orderBy('created_at', 'desc'),
            'ends_asc' => $query->orderBy('ends_at', 'asc'),
            'ends_desc' => $query->orderBy('ends_at', 'desc'),
            default => $query->orderBy('created_at', 'desc'),
        };

        $subscriptions = $query->get();

        if ($subscriptions->isEmpty()) {
            $this->warn('No subscriptions found matching your criteria.');
            return self::SUCCESS;
        }

        $this->info("Found <fg=cyan>{$subscriptions->count()}</> subscription(s):\n");

        $tableRows = [];

        foreach ($subscriptions as $subscription) {
            $user = $subscription->subscribable;
            $plan = $subscription->plan;

            // Status
            $status = $subscription->isActive()
                ? '<fg=green>Active</>'
                : ($subscription->canceled_at
                    ? '<fg=red>Canceled</>'
                    : '<fg=yellow>Expired</>');

            // Dates
            $starts = $subscription->starts_at?->format('M d Y') ?? '-';
            $ends = $subscription->ends_at?->format('M d Y H:i') ?? '∞';
            if ($subscription->ends_at) {
                $remaining = $subscription->ends_at->diffForHumans(now(), ['parts' => 2, 'short' => true]);
                $ends .= " (<fg=" . ($subscription->ends_at->isFuture() ? 'green' : 'red') . ">{$remaining}</>)";
            }

            // Gateway badge
            $gatewayBadge = match($subscription->gateway->value) {
                'fake' => '<fg=magenta>FAKE</>',
                'paypal' => '<fg=blue>PayPal</>',
                'xendit' => '<fg=cyan>Xendit</>',
                'google' => '<fg=green>Google</>',
                'apple' => '<fg=white;bg=black>Apple</>',
                default => strtoupper($subscription->gateway->value),
            };

            // Renewal count (fake only)
            $renewals = $subscription->gateway->value === 'fake'
                ? "{$subscription->renewal_count}/" . (config('subscription.gateways.fake.auto_renew.max_renewals') ?? '∞')
                : '-';

            // Latest transaction
            $latestTxn = $subscription->transactions->sortByDesc('created_at')->first();
            $txn = $latestTxn
                ? "{$latestTxn->type} (" . number_format($latestTxn->amount, 2) . " {$latestTxn->currency})"
                : '<fg=gray>None</>';

            $tableRows[] = [
                $subscription->id,
                $user?->name ?? "User {$subscription->subscribable_id}",
                $plan->name,
                $gatewayBadge,
                $status,
                $starts,
                $ends,
                $renewals,
                $txn,
            ];
        }

        $this->table(
            ['ID', 'User', 'Plan', 'Gateway', 'Status', 'Started', 'Ends', 'Renewals (Fake)', 'Latest Txn'],
            $tableRows
        );

        return self::SUCCESS;
    }
}