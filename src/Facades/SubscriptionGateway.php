<?php

namespace Imannms000\LaravelUnifiedSubscriptions\Facades;

use Illuminate\Support\Facades\Facade;
use Imannms000\LaravelUnifiedSubscriptions\GatewayManager;

class SubscriptionGateway extends Facade
{
    protected static function getFacadeAccessor()
    {
        return GatewayManager::class;
    }

    public static function driver(string $name)
    {
        return app(GatewayManager::class)->driver($name);
    }
}