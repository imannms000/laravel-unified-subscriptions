<?php

namespace Imannms000\LaravelUnifiedSubscriptions\Commands;

use Illuminate\Console\Command;
use Imannms000\LaravelUnifiedSubscriptions\Models\Plan;
use Imannms000\LaravelUnifiedSubscriptions\Models\PlanGatewayPrice;

class AddPlanGatewayPriceCommand extends Command
{
    protected $signature = 'subscription:gateway-price:add
                            {plan : Plan ID or slug}
                            {gateway : Gateway name (google, apple, paypal, xendit, etc.)}
                            {price : Price amount (decimal)}
                            {currency=USD : Currency code}
                            {--plan-id= : gateway_plan_id (e.g. basePlanId for Google)}
                            {--offer-id= : gateway_offer_id (e.g. offerId for Google/Apple)}
                            {--product-id= : gateway_product_id (e.g. productId for Google)}
                            {--plan-name= : Human-readable gateway plan name (optional)  (e.g. Monthly)}';

    protected $description = 'Add or update a gateway-specific price for a subscription plan';

    public function handle(): int
    {
        $planIdentifier = $this->argument('plan');
        $gateway = strtolower(trim($this->argument('gateway')));
        $price = (float) $this->argument('price');
        $currency = strtoupper(trim($this->argument('currency')));

        // Find the plan
        $plan = Plan::where('id', $planIdentifier)
                    ->orWhere('slug', $planIdentifier)
                    ->first();

        if (!$plan) {
            $this->error("Plan '{$planIdentifier}' not found.");
            return self::FAILURE;
        }

        // Validate price
        if ($price <= 0) {
            $this->error('Price must be greater than 0.');
            return self::INVALID;
        }

        // Optional fields
        $gatewayPlanId   = $this->option('plan-id');
        $gatewayOfferId  = $this->option('offer-id');
        $gatewayProductId = $this->option('product-id');
        $gatewayPlanName = $this->option('plan-name');

        // Create or update the gateway price record
        $gatewayPrice = PlanGatewayPrice::updateOrCreate(
            [
                'plan_id' => $plan->id,
                'gateway' => $gateway,
            ],
            [
                'price'              => $price,
                'currency'           => $currency,
                'gateway_plan_id'    => $gatewayPlanId,
                'gateway_offer_id'   => $gatewayOfferId,
                'gateway_product_id' => $gatewayProductId,
                'gateway_plan_name'  => $gatewayPlanName,
            ]
        );

        $this->info("Gateway price for <fg=cyan>{$gateway}</> added/updated for plan <fg=cyan>{$plan->name}</> ({$plan->id}):");

        $this->table(
            ['Field', 'Value'],
            [
                ['Plan ID', $plan->id],
                ['Plan Name', $plan->name],
                ['Plan Slug', $plan->slug],
                ['Gateway', $gateway],
                ['Price', number_format($price, 2) . ' ' . $currency],
                ['gateway_plan_id', $gatewayPlanId ?: '<fg=gray>-</>'],
                ['gateway_offer_id', $gatewayOfferId ?: '<fg=gray>-</>'],
                ['gateway_product_id', $gatewayProductId ?: '<fg=gray>-</>'],
                ['gateway_plan_name', $gatewayPlanName ?: '<fg=gray>-</>'],
            ]
        );

        return self::SUCCESS;
    }
}