<?php

use Illuminate\Support\Facades\Route;
use Imannms000\LaravelUnifiedSubscriptions\Http\Controllers\AppleSubscriptionController;
use Imannms000\LaravelUnifiedSubscriptions\Http\Controllers\GoogleSubscriptionController;
use Imannms000\LaravelUnifiedSubscriptions\Http\Controllers\PayPalSubscriptionController;
use Imannms000\LaravelUnifiedSubscriptions\Http\Controllers\SubscriptionController;
use Imannms000\LaravelUnifiedSubscriptions\Http\Controllers\XenditSubscriptionController;

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/subscriptions/google/verify', [GoogleSubscriptionController::class, 'verify'])
        ->name('api.subscriptions.google.verify');
    Route::post('/subscriptions/apple/verify', [AppleSubscriptionController::class, 'verify'])
        ->name('api.subscriptions.apple.verify');
    Route::post('/subscriptions/paypal', [PayPalSubscriptionController::class, 'store'])
        ->name('api.subscriptions.paypal.store');
    Route::post('/subscriptions/xendit', [XenditSubscriptionController::class, 'store'])
        ->name('api.subscriptions.xendit.store');

    Route::apiResource('subscriptions', SubscriptionController::class)->only(['index', 'destroy']);
});