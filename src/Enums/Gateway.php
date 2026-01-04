<?php

namespace Imannms000\LaravelUnifiedSubscriptions\Enums;

use Imannms000\LaravelUnifiedSubscriptions\Gateways\AppleGateway;
use Imannms000\LaravelUnifiedSubscriptions\Gateways\GoogleGateway;
use Imannms000\LaravelUnifiedSubscriptions\Gateways\PayPalGateway;
use Imannms000\LaravelUnifiedSubscriptions\Gateways\XenditGateway;

enum Gateway: string
{
    case GOOGLE = 'google';
    case APPLE = 'apple';
    case PAYPAL = 'paypal';
    case XENDIT = 'xendit';
    
    public function toGatewayClass()
    {
        return match ($this) {
            self::GOOGLE => GoogleGateway::class,
            self::APPLE => AppleGateway::class,
            self::PAYPAL => PayPalGateway::class,
            self::XENDIT => XenditGateway::class,
        };
    }
}

// How to use:
// $string = 'google';
// $gateway = app(Gateway::from($string)->toGatewayClass());