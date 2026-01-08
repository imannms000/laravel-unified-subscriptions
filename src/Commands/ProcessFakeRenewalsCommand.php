<?php

namespace Imannms000\LaravelUnifiedSubscriptions\Commands;

use Illuminate\Console\Command;
use Imannms000\LaravelUnifiedSubscriptions\Gateways\FakeGateway;
use Imannms000\LaravelUnifiedSubscriptions\Models\Plan;
use Imannms000\LaravelUnifiedSubscriptions\Models\Subscription;

class ProcessFakeRenewalsCommand extends Command
{
    protected $signature = 'subscription:fake:process-renewals';
    protected $description = 'Process auto-renewals for fake gateway subscriptions';

    public function handle(): int
    {
        Subscription::processFakeRenewals();

        $this->info('Fake subscription renewals processed.');

        return self::SUCCESS;
    }
}