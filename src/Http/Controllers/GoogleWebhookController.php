<?php

namespace Imannms000\LaravelUnifiedSubscriptions\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Imannms000\LaravelUnifiedSubscriptions\Enums\Gateway;
use Imannms000\LaravelUnifiedSubscriptions\Http\Controllers\WebhookController;
use Imannms000\LaravelUnifiedSubscriptions\Http\Requests\GoogleWebhookRequest;

class GoogleWebhookController extends WebhookController
{
    protected function resolveGatewayName(): string
    {
        return Gateway::GOOGLE->value;
    }

    /**
     * Handle incoming Google Play Real-time Developer Notifications (RTDN)
     */
    public function handle(GoogleWebhookRequest $request): Response
    {
        // The request has already been validated and decoded by GooglePlayWebhookRequest
        // $request now contains the actual developer notification payload

        $payload = $request->all();

        Log::info('Google Play webhook received', [
            'notification_type' => $payload['subscriptionNotification']['notificationType'] ?? null,
            'purchase_token'    => $payload['subscriptionNotification']['purchaseToken'] ?? null,
        ]);

        try {
            // Pass the decoded payload to the gateway for processing
            parent::handle($request);

            return response()->noContent(200);
        } catch (\Throwable $e) {
            Log::error('Google Play webhook processing failed', [
                'exception'  => $e->getMessage(),
                'trace'      => $e->getTraceAsString(),
                'payload'    => $payload,
            ]);

            // Always return 200 to prevent Google from retrying indefinitely
            return response()->noContent(200);
        }
    }
}