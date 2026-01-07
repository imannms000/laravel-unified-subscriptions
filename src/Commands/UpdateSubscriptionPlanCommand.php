<?php

namespace Imannms000\LaravelUnifiedSubscriptions\Commands;

use Illuminate\Console\Command;
use Imannms000\LaravelUnifiedSubscriptions\Models\Plan;
use Illuminate\Support\Str;
use Imannms000\LaravelUnifiedSubscriptions\Enums\BillingInterval;

class UpdateSubscriptionPlanCommand extends Command
{
    protected $signature = 'subscription:plan:update
                            {id : The ID or slug of the plan to update}
                            {--name= : New name for the plan}
                            {--price= : New default price (e.g., 19.99)}
                            {--currency= : New default currency}
                            {--slug= : New slug (must be unique)}
                            {--interval= : New billing interval (hour, day, week, month, year)}
                            {--interval-count= : New interval count}
                            {--trial-days= : New trial days}
                            {--grace-days= : New grace period days}
                            {--active= : Set plan as active (use --no-active to deactivate)}
                            {--no-active : Deactivate the plan}
                            {--gateway-prices=* : Update or add gateway-specific pricing, format: gateway:price:currency}
                            {--remove-gateway-prices=* : Remove gateway-specific prices by gateway name}';

    protected $description = 'Update an existing subscription plan';

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

        $updates = [];
        $originalData = $plan->toArray();

        // Name
        if ($this->option('name') !== null) {
            $updates['name'] = $this->option('name');
        }

        // Price
        if ($this->option('price') !== null) {
            $updates['price'] = (float) $this->option('price');
        }

        // Currency
        if ($this->option('currency') !== null) {
            $updates['currency'] = strtoupper($this->option('currency'));
        }

        // Slug
        if ($this->option('slug') !== null) {
            $newSlug = Str::slug($this->option('slug'));
            if (Plan::where('slug', $newSlug)->where('id', '!=', $plan->id)->exists()) {
                $this->error("Slug '{$newSlug}' is already taken.");
                return self::FAILURE;
            }
            $updates['slug'] = $newSlug;
            $this->info("Slug will be updated to: <comment>{$newSlug}</comment>");
        }

        // Interval
        if ($this->option('interval') !== null) {
            $interval = BillingInterval::tryFrom($this->option('interval'));
            if (!$interval) {
                $this->error('Invalid interval. Must be one of: ' . implode(', ', array_column(BillingInterval::cases(), 'value')));
                return self::INVALID;
            }
            $updates['interval'] = $interval;
        }

        // Interval Count
        if ($this->option('interval-count') !== null) {
            $updates['interval_count'] = (int) $this->option('interval-count');
        }

        // Trial & Grace Days
        if ($this->option('trial-days') !== null) {
            $updates['trial_days'] = (int) $this->option('trial-days');
        }
        if ($this->option('grace-days') !== null) {
            $updates['grace_days'] = (int) $this->option('grace-days');
        }

        // Active status
        if ($this->option('active') || $this->option('no-active')) {
            $updates['active'] = $this->option('active') && !$this->option('no-active');
        }

        // Apply updates
        if (!empty($updates)) {
            $plan->update($updates);
            $this->info("Plan '{$plan->name}' (ID: {$plan->id}) updated successfully!");
        } else {
            $this->warn('No changes specified for core plan fields.');
        }

        // Handle gateway prices update/add
        $gatewayPrices = $this->option('gateway-prices');
        if (!empty($gatewayPrices)) {
            $this->newLine();
            $this->info('Updating/adding gateway-specific prices...');

            $tableData = [];
            foreach ($gatewayPrices as $input) {
                $parts = explode(':', $input);
                $gateway = strtolower(trim($parts[0] ?? ''));
                $price = (float) trim($parts[1] ?? '');
                $currency = !empty($parts[2]) ? strtoupper(trim($parts[2])) : 'USD';

                if (empty($gateway) || $price <= 0) {
                    $this->warn("Skipping invalid format: {$input}");
                    continue;
                }

                $plan->gatewayPrices()->updateOrCreate(
                    ['gateway' => $gateway],
                    ['price' => $price, 'currency' => $currency]
                );

                $tableData[] = [$gateway, $price, $currency];
            }

            if (!empty($tableData)) {
                $this->table(['Gateway', 'Price', 'Currency'], $tableData);
            }
        }

        // Handle removal of gateway prices
        $removeGateways = $this->option('remove-gateway-prices');
        if (!empty($removeGateways)) {
            $this->newLine();
            $this->info('Removing gateway-specific prices...');

            $removed = [];
            foreach ($removeGateways as $gateway) {
                $gateway = strtolower(trim($gateway));
                if ($plan->gatewayPrices()->where('gateway', $gateway)->delete()) {
                    $removed[] = $gateway;
                }
            }

            if (!empty($removed)) {
                $this->table(['Removed Gateway'], array_map(fn($g) => [$g], $removed));
            } else {
                $this->warn('No gateway prices were removed (not found).');
            }
        }

        $this->newLine();
        $this->info("Plan update complete!");
        $this->line(" <fg=cyan>Name:</> {$plan->fresh()->name}");
        $this->line(" <fg=cyan>Slug:</> {$plan->fresh()->slug}");
        $this->line(" <fg=cyan>Active:</> " . ($plan->active ? '<fg=green>Yes</>' : '<fg=red>No</>'));

        return self::SUCCESS;
    }
}