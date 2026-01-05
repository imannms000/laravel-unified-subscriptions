// src/routes/webhooks.php
<?php

use Illuminate\Support\Facades\Route;
use Imannms000\LaravelUnifiedSubscriptions\Http\Controllers\AppleWebhookController;
use Imannms000\LaravelUnifiedSubscriptions\Http\Controllers\GoogleWebhookController;
use Imannms000\LaravelUnifiedSubscriptions\Http\Controllers\PayPalWebhookController;
use Imannms000\LaravelUnifiedSubscriptions\Http\Controllers\XenditWebhookController;

Route::prefix('subscriptions/webhooks')->name('subscription.webhooks.')->group(function () {
    Route::post('apple', [AppleWebhookController::class, 'handle'])->name('apple');
    Route::post('google', [GoogleWebhookController::class, 'handle'])->name('google');
    Route::post('paypal', [PayPalWebhookController::class, 'handle'])->name('paypal');
    Route::post('xendit', [XenditWebhookController::class, 'handle'])->name('xendit');
});