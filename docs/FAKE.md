# Fake Gateway  

The **Fake Gateway** is a powerful built-in testing tool that allows you to **fully simulate the entire subscription lifecycle locally** — without any real payment providers, sandbox accounts, webhooks, or external services.

Perfect for:
- Local development
- Running tests
- Demos & onboarding
- Debugging renewal/cancellation logic
- Testing emails, notifications, feature access, grace periods, etc.

---

### Enable the Fake Gateway

By default, it's **enabled automatically** in `local` and `testing` environments.

```env
# .env
SUBSCRIPTION_FAKE_GATEWAY_ENABLED=true   # Optional: force enable/disable
```

Config location: `config/subscription.php`

```php
'fake' => [
    'enabled' => env('SUBSCRIPTION_FAKE_GATEWAY_ENABLED', app()->environment(['local', 'testing'])),

    'auto_renew' => [
        'enabled' => true,
        'interval' => 5,           // Renew every 5...
        'unit' => 'minutes',       // ...minutes (supports: seconds, minutes, hours, days)
        'max_renewals' => 6,       // After 6 renewals → auto-cancel (like Google Play test tracks)
    ],
],
```

### How It Works

When you create a subscription with `gateway = 'fake'`:

1. Subscription becomes **active instantly**
2. First billing period ends in **5 minutes** (or your configured interval)
3. Every minute, Laravel checks for due renewals
4. Renews automatically up to **6 times**
5. On the 7th due date → **auto-cancels** the subscription
6. Records realistic transactions and fires all events

### Full Lifecycle Example (default config)

| Time       | Action                          | Renewal Count | ends_at              | Status    |
|------------|---------------------------------|---------------|----------------------|-----------|
| 00:00      | Create fake subscription        | 0             | 00:05                | Active    |
| 00:05      | Auto-renew #1                   | 1             | 00:10                | Active    |
| 00:10      | Auto-renew #2                   | 2             | 00:15                | Active    |
| ...        | ...                             | ...           | ...                  | Active    |
| 00:30      | Auto-renew #6                   | 6             | 00:35                | Active    |
| 00:35      | Max renewals reached → auto-cancel | -          | 00:35                | Canceled  |

### Artisan Commands (Your Testing Superpowers)

```bash
# Create a fake subscription instantly
php artisan subscription:fake:create {userId} {planSlug}

# Example:
php artisan subscription:fake:create 1 premium-monthly
```

```bash
# List all subscriptions with fake gateway to get subscriptionId
php artisan subscription:list --fake
```

```bash
# Check detailed status of any fake subscription
php artisan subscription:fake:status {subscriptionId}

# Shows: plan, ends_at, renewal count, transactions, next renew, etc.
```

```bash
# Manually trigger actions
php artisan subscription:fake:renew {subscriptionId}     # Force immediate renewal
php artisan subscription:fake:expire {subscriptionId}    # Force expiry now
php artisan subscription:fake:cancel {subscriptionId}    # Cancel immediately
```

### Using in Code

```php
// Create fake subscription programmatically
$user->subscriptions()->create([
    'plan_id' => $plan->id,
    'gateway' => 'fake',   // Magic happens here
]);

// The FakeGateway handles everything automatically
```

### Events & Transactions

The fake gateway behaves **exactly like real ones**:

- Fires `SubscriptionCreated`, `SubscriptionRenewed`, `SubscriptionCanceled`
- Records realistic transactions (`payment`, `renewal`, `expiry`)
- Updates `ends_at`, `canceled_at`, `renewal_count`

All your listeners, notifications, and analytics work perfectly.

### Safety Features

- **Disabled in production** by default
- Throws exception if accidentally used in prod
- All logs prefixed with `[FakeGateway]`
- No external network calls

### Testing Tips

1. Set short interval for fast feedback:
   ```php
   'interval' => 1,
   'unit' => 'minutes',
   'max_renewals' => 3,
   ```

2. Watch logs:
   ```bash
   tail -f storage/logs/laravel.log | grep FakeGateway
   ```

3. Use `subscription:fake:status` to see real-time countdown

4. Combine with feature usage testing:
   ```php
   $user->recordFeatureUsage('api_calls', 50);
   // Watch quota reset on each fake renewal
   ```

### Why This Fake Gateway Is Special

Unlike basic stubs, this one **simulates time and lifecycle**:
- Initial period → multiple renewals → natural end
- Inspired by Google Play’s excellent test subscription behavior
- No need to mock dates or manipulate Carbon
- Just run your app and watch subscriptions evolve
