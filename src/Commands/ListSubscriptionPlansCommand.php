<?php

namespace Imannms000\LaravelUnifiedSubscriptions\Commands;

use Illuminate\Console\Command;
use Imannms000\LaravelUnifiedSubscriptions\Models\Plan;

class ListSubscriptionPlansCommand extends Command
{
    protected $signature = 'subscription:plan:list
                            {--active : Show only active plans}
                            {--inactive : Show only inactive plans}
                            {--tier= : Filter by tier-specific pricing (e.g., premium)}
                            {--gateway= : Filter by gateway-specific pricing (e.g., paypal)}
                            {--search= : Search in name or slug}
                            {--sort=id_desc : Sort results (options: id_asc, id_desc, name_asc, name_desc, price_asc, price_desc)}';

    protected $description = 'List all subscription plans with optional filters';

    public function handle(): int
    {
        $query = Plan::query();

        // Filter by active status
        if ($this->option('active')) {
            $query->where('active', true);
        } elseif ($this->option('inactive')) {
            $query->where('active', false);
        }

        // Search in tier
        if ($tier = $this->option('tier')) {
            $query->where(function ($q) use ($tier) {
                $q->where('tier', $tier);
            });
        }

        // Search in name or slug
        if ($search = $this->option('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('slug', 'like', "%{$search}%");
            });
        }

        // Preload gateway prices if filtering or displaying them
        $hasGatewayFilter = $this->option('gateway');
        if ($hasGatewayFilter || true) { // Always load for rich display
            $query->with('gatewayPrices');
        }

        // Filter by gateway-specific pricing
        if ($gateway = $this->option('gateway')) {
            $query->whereHas('gatewayPrices', function ($q) use ($gateway) {
                $q->where('gateway', strtolower($gateway));
            });
        }

        // Sorting
        $sortOption = $this->option('sort');
        match ($sortOption) {
            'id_asc' => $query->orderBy('id', 'asc'),
            'id_desc' => $query->orderBy('id', 'desc'),
            'name_asc' => $query->orderBy('name', 'asc'),
            'name_desc' => $query->orderBy('name', 'desc'),
            'price_asc' => $query->orderBy('price', 'asc'),
            'price_desc' => $query->orderBy('price', 'desc'),
            default => $query->orderBy('id', 'desc'),
        };

        $plans = $query->get();

        if ($plans->isEmpty()) {
            $this->warn('No plans found matching your criteria.');
            return self::SUCCESS;
        }

        $this->info("Found <fg=cyan>{$plans->count()}</> subscription plan(s):\n");

        $tableRows = [];

        foreach ($plans as $plan) {
            $status = $plan->active 
                ? '<fg=green>Active</>' 
                : '<fg=red>Inactive</>';

            $price = number_format($plan->price, 2) . ' ' . $plan->currency;

            $interval = ucfirst($plan->interval->value);
            if ($plan->interval_count > 1) {
                $interval = "{$plan->interval_count} {$interval}s";
            }

            $trial = $plan->trial_days > 0 ? "{$plan->trial_days} days" : '-';
            $grace = $plan->grace_days > 0 ? "{$plan->grace_days} days" : '-';

            // Gateway prices
            $gateways = $plan->gatewayPrices->map(function ($gp) {
                return "<fg=yellow>{$gp->gateway}:</> " . number_format($gp->price, 2) . " {$gp->currency}";
            })->implode(', ');

            $gateways = $gateways ?: '<fg=gray>None</>';

            $tableRows[] = [
                $plan->id,
                $plan->tier,
                $plan->name,
                $plan->slug,
                $price,
                $interval,
                $status,
                $trial,
                $grace,
                $gateways,
            ];
        }

        $this->table(
            ['ID', 'Tier', 'Name', 'Slug', 'Default Price', 'Interval', 'Status', 'Trial', 'Grace', 'Gateway Prices'],
            $tableRows
        );

        return self::SUCCESS;
    }
}