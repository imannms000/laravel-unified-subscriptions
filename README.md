# Laravel Unified Subscriptions

A powerful, extensible, and production-ready Laravel package for managing recurring subscriptions across multiple payment gateways:

- **PayPal**
- **Xendit**
- [**Google Play Billing**](./docs/GOOGLE.md)
- **Apple App Store**
- [**Fake** (local testing)](./docs/FAKE.md)

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
            'currencies' => ['IDR', 'PHP', 'MYR', 'THB', 'VND', 'SGD'],
        ],
        'google' => [
            'driver' => \Imannms000\LaravelUnifiedSubscriptions\Gateways\GoogleGateway::class,
            'package_name' => env('GOOGLE_PLAY_PACKAGE_NAME'),
            'service_account' => env('GOOGLE_PLAY_SERVICE_ACCOUNT', base_path('google-play-service-account.json')),
            'obfuscation_salt' => env('GOOGLE_PLAY_OBFUSCATION_SALT', 'default-stable-salt-change-this-in-prod'),
        ],
        'apple' => [
            'driver' => \Imannms000\LaravelUnifiedSubscriptions\Gateways\AppleGateway::class,
            'shared_secret' => env('APPLE_SHARED_SECRET'),
            'sandbox' => env('APPLE_SANDBOX', true),
        ],
        'fake' => [
            'driver' => \Imannms000\LaravelUnifiedSubscriptions\Gateways\FakeGateway::class,
            'enabled' => env('SUBSCRIPTION_FAKE_GATEWAY_ENABLED', app()->environment(['local', 'testing'])),

            'auto_renew' => [
                'enabled' => true,
                'interval' => 5,           // Renew every X
                'unit' => 'minutes',       // seconds, minutes, hours, days
                'max_renewals' => 6,       // After 6 renewals → auto-cancel (initial + 6 = 7 periods)
            ],
        ],
    ],
    'routes' => [
        'api' => [
            'middleware' => env('SUBSCRIPTION_API_MIDDLEWARE', ['api', 'auth:sanctum']),
            'prefix' => env('SUBSCRIPTION_API_PREFIX', 'api/v1'),
        ]
    ]

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

    NOTE: For Google Play integrations, the `createSubscription` method should be omitted. The system automatically provisions the subscription via Real-Time Developer Notifications (RTDN).

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

## Commands

### \# Plan

### Create a Plan

#### a. Basic monthly plan
```bash
php artisan subscription:plan:create "premium" "Premium Monthly" 9.99 --interval=month --trial-days=7 --active
```

#### b. Yearly plan with custom slug
```bash
php artisan subscription:plan:create "pro" "Pro Yearly" 99.99 --slug=pro-annual --interval=year --active
```

#### c. Free plan
```bash
php artisan subscription:plan:create "free" "Free Forever" 0.00 --active
```

#### d. Plan with gateway-specific pricing (Xendit in IDR)
```bash
php artisan subscription:plan:create "premium" "Premium (Indonesia)" 9.99 \
  --gateway-prices=xendit:150000.00:IDR \
  --gateway-prices=paypal:9.99:USD \
  --active
```

### List a Plan

#### a. List All Plans (Default: newest first)
```bash
php artisan subscription:plan:list
```

#### b. Only Active Plans
```bash
php artisan subscription:plan:list --active
```

#### c. Only Inactive Plans
```bash
php artisan subscription:plan:list --inactive
```

#### d. Filter by Gateway-Specific Pricing
```bash
php artisan subscription:plan:list --gateway=google
```

#### e. Filter by Tier
```bash
php artisan subscription:plan:list --tier=pro
```

#### f. Search by Name or Slug
```bash
php artisan subscription:plan:list --search=premium
```

#### g. Sort by Price (Cheapest First)
```bash
php artisan subscription:plan:list --sort=price_asc
```

#### h. Combine Filters
```bash
php artisan subscription:plan:list \
  --active \
  --gateway=paypal \
  --tier=pro \
  --sort=name_asc
```

#### i. Sample Output
```text
Found 5 subscription plan(s):

+----+--------------------+-------------------+-----------------+-------------+----------+--------+-------+----------------------------------+
| ID | Name               | Slug              | Default Price   | Interval    | Status   | Trial  | Grace | Gateway Prices                   |
+----+--------------------+-------------------+-----------------+-------------+----------+--------+-------+----------------------------------+
| 8  | Premium Monthly    | premium-monthly   | 9.99 USD        | 1 Month     | Active   | 7 days | -     | xendit: 150000.00 IDR, paypal: 9.99 USD |
| 7  | Pro Yearly         | pro-yearly        | 99.99 USD       | 1 Year      | Active   | 14 days| 3 days| xendit: 1400000.00 IDR           |
| 3  | Basic Free         | basic-free        | 0.00 USD        | 1 Month     | Active   | -      | -     | None                             |
+----+--------------------+-------------------+-----------------+-------------+----------+--------+-------+----------------------------------+
```

### Update a Plan

#### a. Basic Update (Change Price & Name) (using Slug)
```bash
php artisan subscription:plan:update premium-monthly \
  --name="Premium Plus" \
  --price=19.99
```

#### b. Update Slug & Deactivate (using ID)
```bash
php artisan subscription:plan:update 01KED7BB2NMW5BHXCWAAW3HGTT \
  --slug=premium-plus-new \
  --no-active
```

#### c. Update Interval & Trial (using Slug)
```bash
php artisan subscription:plan:update premium-monthly \
  --interval=year \
  --interval-count=1 \
  --trial-days=30
```

#### d. Update/Add Gateway Prices (using ID)
```bash
php artisan subscription:plan:update 01KED7A1HNMG2Y0NTCW08QPYYT \
  --gateway-prices=xendit:250000:IDR \
  --gateway-prices=paypal:19.99:USD
```

#### e. Remove Gateway Price (using Slug)
```bash
php artisan subscription:plan:update premium-monthly \
  --remove-gateway-prices=xendit \
  --remove-gateway-prices=paypal
```

#### f. Full Overhaul (using ID)
```bash
php artisan subscription:plan:update 01KED78ESDFW7CXJG7PF382818 \
  --name="Enterprise Annual" \
  --price=999.00 \
  --interval=year \
  --trial-days=60 \
  --active \
  --gateway-prices=xendit:15000000:IDR
```

### Delete a Plan

#### a. Normal Delete (with Confirmation)
```bash
php artisan subscription:plan:delete premium-old
```
Shows plan details, asks for confirmation, deletes if no active subs.

#### b. Force Delete (No Confirmation)
```bash
php artisan subscription:plan:delete 5 --force
```
Skips confirmation, permanently deletes.

#### c. Attempt to Delete Plan with Active Subscriptions
```bash
php artisan subscription:plan:delete premium-monthly
```
Shows error: `"Cannot delete... has X active subscription(s)"`

#

### \# Plan Gateway Prices

### Add a Gateway Price

#### a. Add Google Play base plan
```bash
# using plan slug
php artisan subscription:gateway-price:add \
  premium-monthly \
  google_play \
  9.99 \
  USD \
  --plan-id=premium_monthly \
  --offer-id=premium_monthly_trial \
  --product-id=premium \
  --plan-name="Premium Monthly - Trial"
```

#### b. Add PayPal plan (no offer/product)
```bash
# using plan id
php artisan subscription:gateway-price:add \
  01J9K9M7P8X3R3C4U6N6W7X0YSS \
  paypal \
  9.99 \
  USD \
  --plan-id=P-2UF78835G6984125U \
  --plan-name="PayPal Premium Monthly"
```

#### c. Update existing
```bash
php artisan subscription:gateway-price:add \
  premium-monthly \
  google \
  12.99 \
  USD \
  --plan-id=premium_monthly
```

### List Gateway Prices

#### a. List all gateway prices

```bash
php artisan subscription:gateway-price:list
```

#### b. List only for specific plan

```bash
# by plan slug
php artisan subscription:gateway-price:list premium-monthly
# or by plan ID
php artisan subscription:gateway-price:list 01J9K9M7P8X3R3C4U6N6W7X0YSS
```

#### c. Filter by gateway
```bash
php artisan subscription:gateway-price:list --gateway=paypal
```

#### d. Show only active plan
```bash
php artisan subscription:gateway-price:list --active
```

#### e. Search across plan name or gateway plan name
```bash
php artisan subscription:gateway-price:list --search=trial
```

#### f. Sample output

```text
Found 6 gateway price record(s):

+---------+---------------------+-------------------+-------------+-----------------+------------------+-------------------+---------------------------+----------+-------------+
| Plan ID | Plan Name           | Plan Slug         | Gateway     | gateway_plan_id | gateway_offer_id | gateway_product_id | gateway_plan_name         | Price    | Plan Status |
+---------+---------------------+-------------------+-------------+-----------------+------------------+-------------------+---------------------------+----------+-------------+
| 1       | Premium Monthly     | premium-monthly   | google_play | premium_monthly | trial-7days      | premium           | Premium Monthly - Trial   | 0.00 USD | Active      |
| 1       | Premium Monthly     | premium-monthly   | paypal      | P-2UF78835...   | -                | -                 | PayPal Premium Monthly    | 9.99 USD | Active      |
| 2       | Pro Yearly          | pro-yearly        | google_play | pro_yearly      | -                | pro               | Pro Yearly Base           | 99.00 USD| Active      |
+---------+---------------------+-------------------+-------------+-----------------+------------------+-------------------+---------------------------+----------+-------------+
```

### Delete a Gateway Prices

#### a. Delete single entry

```bash
php artisan subscription:gateway-price:remove 01J8K9M7P8Q2R3T4U5V6W7X8Y9Z
```

#### b. Delete all entries under specific plan and gateway

```bash
php artisan subscription:gateway-price:remove premium-monthly --gateway=google
```

#### c. Delete all entries under specific plan
```bash
php artisan subscription:gateway-price:remove premium-monthly
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