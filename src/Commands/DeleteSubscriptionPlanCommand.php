<?php

namespace Imannms000\LaravelUnifiedSubscriptions\Commands;

use Illuminate\Console\Command;
use Imannms000\LaravelUnifiedSubscriptions\Models\Plan;

class DeleteSubscriptionPlanCommand extends Command
{
    protected $signature = 'subscription:plan:delete
                            {id : The ID or slug of the plan to delete}
                            {--force : Bypass confirmation and force delete}';

    protected $description = 'Delete a subscription plan (with safety checks)';

    public function handle(): int
    {
        $identifier = $this->argument('id');

        $plan = Plan::where('id', $identifier)
            ->orWhere('slug', $identifier)
            ->first();

        if (!$plan) {
            $this->error("Plan with ID or slug '{$identifier}' not found.");
            return self::FAILURE;
        }

        // Show plan details
        $this->newLine();
        $this->info("Plan to be deleted:");
        $this->table(
            ['Field', 'Value'],
            [
                ['ID', $plan->id],
                ['Tier', $plan->tier],
                ['Name', $plan->name],
                ['Slug', $plan->slug],
                ['Default Price', number_format($plan->price, 2) . ' ' . $plan->currency],
                ['Interval', ucfirst($plan->interval->value) . ($plan->interval_count > 1 ? " ×{$plan->interval_count}" : '')],
                ['Status', $plan->trashed() ? '<fg=red>Soft Deleted</>' : ($plan->active ? '<fg=green>Active</>' : '<fg=yellow>Inactive</>')],
                ['Active Subscriptions', $plan->subscriptions()->count()],
                ['Gateway Prices', $plan->gatewayPrices()->count()],
            ]
        );

        // Safety check: prevent deletion if plan has active subscriptions
        $activeSubs = $plan->subscriptions()->whereNull('canceled_at')->where(function ($q) {
            $q->whereNull('ends_at')->orWhere('ends_at', '>', now());
        })->count();

        if ($activeSubs > 0) {
            $this->error("Cannot delete plan '{$plan->name}' — it has {$activeSubs} active subscription(s).");
            $this->line("Cancel or migrate those subscriptions first.");
            return self::FAILURE;
        }

        // Confirmation (unless --force)
        if (!$this->option('force') && !$this->option('soft')) {
            if (!$this->confirm("Are you sure you want to delete the plan '{$plan->name}'? This action cannot be undone.", false)) {
                $this->info('Deletion cancelled.');
                return self::SUCCESS;
            }
        }

        // Perform deletion
        if ($this->option('soft') && method_exists($plan, 'trashed') && !$plan->trashed()) {
            $plan->delete(); // Soft delete
            $this->info("Plan '{$plan->name}' has been <fg=yellow>soft deleted</>.");
            $this->line("Use --force to permanently delete.");
        } else {
            // Force delete (permanent) — also deletes related gateway prices
            $planName = $plan->name;
            $plan->forceDelete();
            $this->info("Plan '{$planName}' has been <fg=red>permanently deleted</>.");
        }

        return self::SUCCESS;
    }
}