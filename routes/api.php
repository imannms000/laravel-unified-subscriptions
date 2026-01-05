<?php

use Illuminate\Support\Facades\Route;
use Imannms000\LaravelUnifiedSubscriptions\Http\Controllers\AppleSubscriptionController;
use Imannms000\LaravelUnifiedSubscriptions\Http\Controllers\GoogleSubscriptionController;
use Imannms000\LaravelUnifiedSubscriptions\Http\Controllers\PayPalSubscriptionController;
use Imannms000\LaravelUnifiedSubscriptions\Http\Controllers\SubscriptionController;
use Imannms000\LaravelUnifiedSubscriptions\Http\Controllers\XenditSubscriptionController;

Route::middleware(config('subscription.routes.api.middleware', ['api', 'auth:sanctum']))
    ->prefix(config('subscription.routes.api.prefix', 'api/v1'))
    ->name('api.')
    ->group(function () {
        
        Route::prefix('subscriptions')->group(function () {
            Route::post('google/verify', [GoogleSubscriptionController::class, 'verify'])
                ->name('subscriptions.google.verify');
            Route::post('apple/verify', [AppleSubscriptionController::class, 'verify'])
                ->name('subscriptions.apple.verify');
            Route::post('paypal', [PayPalSubscriptionController::class, 'store'])
                ->name('subscriptions.paypal.store');
            Route::post('xendit', [XenditSubscriptionController::class, 'store'])
                ->name('subscriptions.xendit.store');
        });

        // Resource Routes
        Route::apiResource('subscriptions', SubscriptionController::class)
            ->only(['index', 'destroy']);
    });