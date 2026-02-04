<?php

namespace Imannms000\LaravelUnifiedSubscriptions\Commands;

use Illuminate\Console\Command;
use Imannms000\LaravelUnifiedSubscriptions\Models\Plan;
use Imannms000\LaravelUnifiedSubscriptions\Models\PlanGatewayPrice;

class RemovePlanGatewayPriceCommand extends Command
{
    protected $signature = 'subscription:gateway-price:remove
                            {identifier : The Plan ID, slug, or specific Entry ID}
                            {--gateway= : Filter by a specific provider (e.g., google, apple)}
                            {--force : Suppress confirmation prompts and execute immediately}';

    protected $description = 'Remove gateway-specific pricing for a subscription plan';

    public function handle(): int
    {
        $identifier = trim($this->argument('identifier'));
        $gateway = $this->option('gateway') ? strtolower(trim($this->option('gateway'))) : null;

        // Case 1: Single entry removal
        $gatewayPrice = PlanGatewayPrice::find($identifier);

        if ($gatewayPrice) {
            $plan = $gatewayPrice->plan;

            $this->newLine();
            $this->info("Removing SINGLE gateway price entry:");
            $this->table(
                ['Field', 'Value'],
                [
                    ['Entry ID', $gatewayPrice->id],
                    ['Plan ID', $plan->id],
                    ['Plan Name', $plan->name],
                    ['Plan Slug', $plan->slug],
                    ['Gateway', $gatewayPrice->gateway],
                    ['Price', number_format($gatewayPrice->price, 2) . ' ' . $gatewayPrice->currency],
                    ['gateway_plan_id', $gatewayPrice->gateway_plan_id ?: '<fg=gray>-</>'],
                    ['gateway_offer_id', $gatewayPrice->gateway_offer_id ?: '<fg=gray>-</>'],
                    ['gateway_product_id', $gatewayPrice->gateway_product_id ?: '<fg=gray>-</>'],
                    ['gateway_plan_name', $gatewayPrice->gateway_plan_name ?: '<fg=gray>-</>'],
                ]
            );

            if (!$this->option('force') && !$this->confirm("Delete this single entry?", false)) {
                $this->info('Cancelled.');
                return self::SUCCESS;
            }

            $gatewayPrice->delete();

            $this->info("<fg=green>Removed single entry {$gatewayPrice->id} for {$gatewayPrice->gateway}.</>");
            return self::SUCCESS;
        }

        // Case 2: Remove one or all gateways
        $plan = Plan::where('id', $identifier)
                    ->orWhere('slug', $identifier)
                    ->first();

        if (!$plan) {
            $this->error("Plan '{$identifier}' not found.");
            return self::FAILURE;
        }

        $pricesQuery = $plan->gatewayPrices();

        $prices = $pricesQuery->get();

        if ($prices->isEmpty()) {
            $this->warn("No gateway prices found for plan '{$plan->name}'.");
            return self::SUCCESS;
        }

        // Filter by gateway if provided
        if ($gateway) {
            $pricesQuery->where('gateway', $gateway);
            $prices = $pricesQuery->get();

            $desc = $prices->count() === 1
                ? "1 gateway price entry for '{$gateway}'"
                : "{$prices->count()} entries for '{$gateway}'";
        } else {
            $desc = "ALL {$prices->count()} gateway price entries";
        }

        // Show preview
        $this->newLine();
        $this->info("Will remove {$desc} from plan <fg=cyan>{$plan->name}</> ({$plan->slug}):");

        $tableRows = $prices->map(fn($p) => [
            $p->id,
            $p->gateway,
            number_format($p->price, 2) . ' ' . $p->currency,
            $p->gateway_plan_id ?: '-',
            $p->gateway_offer_id ?: '-',
            $p->gateway_product_id ?: '-',
            $p->gateway_plan_name ?: '-',
        ])->toArray();

        $this->table(
            ['ID', 'Gateway', 'Price', 'gateway_plan_id', 'gateway_offer_id', 'gateway_product_id', 'gateway_plan_name'],
            $tableRows
        );

        // Confirmation
        if (!$this->option('force')) {
            if (!$this->confirm("Delete {$prices->count()} entr" . ($prices->count() === 1 ? 'y' : 'ies') . "?", false)) {
                $this->info('Cancelled.');
                return self::SUCCESS;
            }
        }

        // Delete
        $pricesQuery->delete();

        $this->info("<fg=green>Removed {$prices->count()} gateway price entr" . ($prices->count() === 1 ? 'y' : 'ies') . " from plan '{$plan->name}'.</>");

        return self::SUCCESS;
    }
}