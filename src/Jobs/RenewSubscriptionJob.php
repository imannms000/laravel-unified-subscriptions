<?php

namespace Imannms000\LaravelUnifiedSubscriptions\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Imannms000\LaravelUnifiedSubscriptions\Models\Subscription;
use Carbon\Carbon;

class RenewSubscriptionsJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function handle(): void
    {
        Subscription::query()
            ->whereNotNull('ends_at')
            ->where('ends_at', '<=', Carbon::today()->endOfDay())
            ->whereNull('canceled_at')
            ->each(function (Subscription $subscription) {
                $subscription->renew();
            });
    }
}