<?php

return [
    'models' => [
        'user' => env('SUBSCRIPTION_MODEL_USER', \App\Models\User::class),
    ],
    'gateways' => [
        'paypal' => [
            'driver' => \Imannms000\LaravelUnifiedSubscriptions\Gateways\PayPalGateway::class,
            'mode' => env('PAYPAL_MODE', 'sandbox'), // or live
            'client_id' => env('PAYPAL_CLIENT_ID'),
            'client_secret' => env('PAYPAL_CLIENT_SECRET'),
            'webhook_id' => env('PAYPAL_WEBHOOK_ID'),
            'use_custom_id' => env('PAYPAL_USE_CUSTOM_ID', true),
            'return_url' => env('PAYPAL_RETURN_URL'),
            'cancel_url' => env('PAYPAL_CANCEL_URL'),
        ],
        'xendit' => [
            'driver' => \Imannms000\LaravelUnifiedSubscriptions\Gateways\XenditGateway::class,
            'secret_key' => env('XENDIT_SECRET_KEY'),
            'callback_token' => env('XENDIT_CALLBACK_TOKEN'),
        ],
        'google' => [
            'driver' => \Imannms000\LaravelUnifiedSubscriptions\Gateways\GoogleGateway::class,
            'package_name' => env('GOOGLE_PLAY_PACKAGE_NAME'),
            'service_account' => env('GOOGLE_PLAY_SERVICE_ACCOUNT', base_path('google-play-service-account.json')),
        ],
        'apple' => [
            'driver' => \Imannms000\LaravelUnifiedSubscriptions\Gateways\AppleGateway::class,
            'shared_secret' => env('APPLE_SHARED_SECRET'),
            'sandbox' => env('APPLE_SANDBOX', true),
        ],
    ],
    'routes' => [
        'api' => [
            'middleware' => env('SUBSCRIPTION_API_MIDDLEWARE', ['api', 'auth:sanctum']),
            'prefix' => env('SUBSCRIPTION_API_PREFIX', 'api/v1'),
        ]
    ]

];