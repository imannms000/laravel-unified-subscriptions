<?php

namespace Imannms000\LaravelUnifiedSubscriptions\Commands;

use Illuminate\Console\Command;
use Imannms000\LaravelUnifiedSubscriptions\Models\Plan;
use Illuminate\Support\Str;
use Imannms000\LaravelUnifiedSubscriptions\Enums\BillingInterval;

class CreateSubscriptionPlanCommand extends Command
{
    protected $signature = 'subscription:plan:create
                            {tier : The name of the tier (e.g., premium)}
                            {name : The name of the plan (e.g., Premium Monthly)}
                            {price : Default price (e.g., 9.99 for $9.99)}
                            {currency=USD : Default currency}
                            {--slug= : Custom slug (optional; auto-generated from name if not provided)}
                            {--interval=month : Billing interval (hour, day, week, month, year) or use BillingInterval::enum}
                            {--interval-count=1 : Number of intervals per billing cycle}
                            {--trial-days=0 : Number of trial days}
                            {--grace-days=0 : Grace period after expiration}
                            {--active : Mark plan as active}
                            {--gateway-prices=* : Gateway-specific pricing, format: gateway:price:currency (e.g., xendit:150000.00:IDR)}';

    protected $description = 'Create a new subscription plan with optional gateway-specific pricing';

    public function handle(): int
    {
        $tier = strtolower( $this->argument('tier') );
        $name = $this->argument('name');

        // Auto-generate slug if not provided
        $slug = $this->option('slug');
        if (empty($slug)) {
            $slug = Str::slug($name);
            $originalSlug = $slug;
            $counter = 1;
            while (Plan::where('slug', $slug)->exists()) {
                $slug = $originalSlug . '-' . $counter;
                $counter++;
            }
            $this->info("Auto-generated slug: <comment>{$slug}</comment>");
        } else if (Plan::where('slug', $slug)->exists()) {
            $this->error("Slug '{$slug}' already exists. Choose a different one.");
            return self::FAILURE;
        }

        $data = [
            'tier' => $tier,
            'name' => $name,
            'slug' => $slug,
            'price' => (float) $this->argument('price'), // Store as decimal
            'currency' => strtoupper($this->argument('currency')),
            'interval' => BillingInterval::tryFrom($this->option('interval')) ?? null,
            'interval_count' => (int) $this->option('interval-count'),
            'trial_days' => (int) $this->option('trial-days'),
            'grace_days' => (int) $this->option('grace-days'),
            'active' => $this->option('active'),
        ];

        if (!$data['interval']) {
            $this->error('Invalid interval. Must be one of: ' . implode(', ', array_column(BillingInterval::cases(), 'value')));
            return self::INVALID;
        }

        $plan = Plan::create($data);

        $this->info("Plan '{$plan->name}' created successfully with slug: <fg=green>{$plan->slug}</>");

        // Gateway-specific prices
        $gatewayPrices = $this->option('gateway-prices');
        if (!empty($gatewayPrices)) {
            $this->newLine();
            $this->info('Adding gateway-specific prices...');

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

                $plan->gatewayPrices()->create([
                    'gateway' => $gateway,
                    'price' => $price,
                    'currency' => $currency,
                ]);

                $tableData[] = [$gateway, $price, $currency];
            }

            if (!empty($tableData)) {
                $this->table(['Gateway', 'Price', 'Currency'], $tableData);
            }
        }

        $this->newLine();
        $this->line(" <fg=cyan>Plan ID:</> {$plan->id}");
        $this->line(" <fg=cyan>Slug:</> {$plan->slug}");

        return self::SUCCESS;
    }
}