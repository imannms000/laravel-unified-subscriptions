<?php

namespace Imannms000\LaravelUnifiedSubscriptions\Commands;

use Illuminate\Console\Command;
use Imannms000\LaravelUnifiedSubscriptions\Models\Plan;
use Imannms000\LaravelUnifiedSubscriptions\Models\PlanGatewayPrice;

class ListPlanGatewayPricesCommand extends Command
{
    protected $signature = 'subscription:gateway-price:list
                            {plan? : Plan ID or slug (optional; shows all if omitted)}
                            {--gateway= : Filter by gateway (e.g., google, paypal)}
                            {--active : Show only prices for active plans}
                            {--inactive : Show only prices for inactive plans}
                            {--search= : Search in plan name, slug, or gateway plan name}';

    protected $description = 'List gateway-specific prices for subscription plans';

    public function handle(): int
    {
        $planIdentifier = $this->argument('plan');
        $query = PlanGatewayPrice::query()->with('plan');

        // Filter by specific plan
        if ($planIdentifier) {
            $plan = Plan::where('id', $planIdentifier)
                        ->orWhere('slug', $planIdentifier)
                        ->first();

            if (!$plan) {
                $this->error("Plan '{$planIdentifier}' not found.");
                return self::FAILURE;
            }

            $query->where('plan_id', $plan->id);
            $this->info("Showing gateway prices for plan: <fg=cyan>{$plan->name}</> ({$plan->slug})");
        } else {
            $this->info('Showing all gateway prices');
        }

        // Filter by gateway
        if ($gateway = $this->option('gateway')) {
            $query->where('gateway', strtolower($gateway));
        }

        // Filter by plan active status
        if ($this->option('active')) {
            $query->whereHas('plan', function ($q) {
                $q->where('active', true);
            });
        } elseif ($this->option('inactive')) {
            $query->whereHas('plan', function ($q) {
                $q->where('active', false);
            });
        }

        // Search in plan name/slug or gateway_plan_name
        if ($search = $this->option('search')) {
            $search = '%' . trim($search) . '%';
            $query->where(function ($q) use ($search) {
                $q->whereHas('plan', function ($q) use ($search) {
                    $q->where('name', 'like', $search)
                      ->orWhere('slug', 'like', $search);
                })->orWhere('gateway_plan_name', 'like', $search);
            });
        }

        $prices = $query->get();

        if ($prices->isEmpty()) {
            $this->warn('No gateway prices found matching your criteria.');
            return self::SUCCESS;
        }

        $this->info("Found <fg=cyan>{$prices->count()}</> gateway price record(s):\n");

        $tableRows = $prices->map(function ($price) {
            $plan = $price->plan;

            return [
                $plan->id,
                $price->id,
                $plan->name,
                $plan->slug,
                $price->gateway,
                $price->gateway_plan_id ?: '<fg=gray>-</>',
                $price->gateway_offer_id ?: '<fg=gray>-</>',
                $price->gateway_product_id ?: '<fg=gray>-</>',
                $price->gateway_plan_name ?: '<fg=gray>-</>',
                number_format($price->price, 2) . ' ' . $price->currency,
                $plan->active ? '<fg=green>Active</>' : '<fg=red>Inactive</>',
            ];
        })->toArray();

        $this->table(
            ['Plan ID', 'Entry ID', 'Plan Name', 'Plan Slug', 'Gateway', 'gateway_plan_id', 'gateway_offer_id', 'gateway_product_id', 'gateway_plan_name', 'Price', 'Plan Status'],
            $tableRows
        );

        return self::SUCCESS;
    }
}