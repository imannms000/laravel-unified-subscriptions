# Laravel Unified Subscriptions

A powerful, extensible, and production-ready Laravel package for managing recurring subscriptions across multiple payment gateways:

- **PayPal**
- **Xendit**
- [**Google Play Billing**](./docs/GOOGLE.md)
- **Apple App Store**

Built the **Laravel way** — using traits, events, policies, and form requests.

## Features

- Unified interface for all gateways (easy to extend)
- Gateway-specific pricing & currency (perfect for local markets like IDR on Xendit)
- Feature usage tracking (quotas, reset on interval)
- Fine-grained billing intervals: day, week, and month
- Transaction history & audit log
- Webhook handling with validation and signature verification
- Events for every lifecycle change (created, renewed, canceled, etc.)
- Policies for authorization
- Form requests for secure webhook validation
- Polymorphic `subscribable` (default: User)
- Full support for trials, grace periods, and cancellations

## Requirements

- PHP 8.2+
- Laravel 12.x
- Database (MySQL, PostgreSQL, SQLite, etc.)

## Installation

```bash
composer require imannms000/laravel-unified-subscriptions
```

Publish config, migrations, and optional assets:

```bash
php artisan vendor:publish --tag=subscription-config
php artisan vendor:publish --tag=subscription-migrations
php artisan vendor:publish --tag=apple-cert
```

Run migrations:

```bash
php artisan migrate
```

Add the `HasSubscriptions` trait to your `User` model:

```php
use Imannms000\LaravelUnifiedSubscriptions\Concerns\HasSubscriptions;

class User extends Authenticatable
{
    use HasSubscriptions;
}
```

## Configuration

Edit `config/subscription.php`:

```php
return [
    'models' => [
        'user' => env('MODEL_USER', \App\Models\User::class),
    ],
    'gateways' => [
        'paypal' => [
            'mode' => env('PAYPAL_MODE', 'sandbox'), // or live
            'client_id' => env('PAYPAL_CLIENT_ID'),
            'client_secret' => env('PAYPAL_CLIENT_SECRET'),
            'webhook_id' => env('PAYPAL_WEBHOOK_ID'),
            'use_custom_id' => env('PAYPAL_USE_CUSTOM_ID', true),
            'return_url' => env('PAYPAL_RETURN_URL'),
            'cancel_url' => env('PAYPAL_CANCEL_URL'),
        ],
        'xendit' => [
            'secret_key' => env('XENDIT_SECRET_KEY'),
            'callback_token' => env('XENDIT_CALLBACK_TOKEN'),
        ],
        'google' => [
            'package_name' => env('GOOGLE_PLAY_PACKAGE_NAME'),
            'service_account' => env('GOOGLE_PLAY_SERVICE_ACCOUNT', base_path('google-play-service-account.json')),
        ],
        'apple' => [
            'shared_secret' => env('APPLE_SHARED_SECRET'),
            'sandbox' => env('APPLE_SANDBOX', true),
        ],
    ],

];
```

## Usage

### Creating Plans

```php
use Imannms000\LaravelUnifiedSubscriptions\Models\Plan;

$plan = Plan::create([
    'name' => 'Premium Monthly',
    'slug' => 'premium-monthly',
    'price' => 3.99, // default price
    'currency' => 'USD',
    'interval' => 'month',
    'interval_count' => 1,
    'trial_days' => 7,
]);

// Optional: gateway-specific pricing
$plan->gatewayPrices()->create([
    'gateway' => Gateway::XENDIT->value,
    'price' => 49999,
    'currency' => 'IDR',
]);
```

### Starting a Subscription

#### Web (PayPal / Xendit)

```php
$subscription = $user->subscriptions()->create(['plan_id' => $plan->id]);

$gateway = SubscriptionGateway::driver(Gateway::PAYPAL->value);
return $gateway->redirectToCheckout($subscription);
```

#### Mobile (Google Play / Apple)

Send receipt from app:

```php
$subscription = $user->subscriptions()->create(['plan_id' => $plan->id]);

app(GoogleGateway::class)->createSubscription($subscription, [
    'purchase_token' => $request->purchase_token,
]);
```

### Checking Subscription Status

```php
if ($user->hasActiveSubscription()) {
    // ...
}

if ($user->subscribedTo($plan)) {
    // ...
}

if ($user->onTrial()) {
    // ...
}
```

### Feature Usage

```php
// Define feature on plan
$plan->features()->create([
    'slug' => 'api_calls',
    'name' => 'API Calls',
    'value' => 1000,
]);

// In your code
if ($user->canUseFeature('api_calls')) {
    $user->recordFeatureUsage('api_calls');
}
```

## Webhooks

Set these URLs in each gateway dashboard:

```
https://yourdomain.com/webhooks/paypal
https://yourdomain.com/webhooks/xendit
https://yourdomain.com/webhooks/google
https://yourdomain.com/webhooks/apple
```

All webhooks are secured with signature verification and validation.

## Events

Listen to events for notifications, analytics, etc.:

```php
use Imannms000\LaravelUnifiedSubscriptions\Events\SubscriptionCreated;
use Imannms000\LaravelUnifiedSubscriptions\Events\SubscriptionRenewed;

Event::listen(SubscriptionCreated::class, function ($event) {
    // Send welcome email
});

Event::listen(SubscriptionRenewed::class, function ($event) {
    // Log renewal
});
```

## Extending with New Gateways

1. Create a class implementing `GatewayInterface`
2. Add to `config/subscription.php` → `'gateways'`
3. Create webhook request extending `WebhookRequest`
4. Create webhook controller extending `WebhookController`
4. Done!

## Contributing

Contributions are welcome! Please open an issue or PR.

## License

MIT License

---

**Happy subscribing!**  
Built with love for clean, scalable subscription systems.