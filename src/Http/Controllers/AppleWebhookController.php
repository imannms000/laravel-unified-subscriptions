<?php

namespace Imannms000\LaravelUnifiedSubscriptions\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Imannms000\LaravelUnifiedSubscriptions\Http\Requests\AppleWebhookRequest;
use Imannms000\LaravelUnifiedSubscriptions\Events\WebhookReceived;
use Imannms000\LaravelUnifiedSubscriptions\Gateways\AppleGateway;
use Exception;
use Imannms000\LaravelUnifiedSubscriptions\Enums\Gateway;
use Imannms000\LaravelUnifiedSubscriptions\Http\Requests\WebhookRequest;

class AppleWebhookController extends WebhookController
{
    protected function resolveGatewayName(): string
    {
        return Gateway::APPLE->value;
    }

    /**
     * Handle Apple App Store Server Notifications (v2)
     * Supports signedPayload JWS format with full chain verification
     */
    public function handle(AppleWebhookRequest $request): Response
    {
        $originalPayload = $request->json()->all();
        $decodedPayload = $request->all(); // After validation/decoding in FormRequest

        Log::info('Apple App Store webhook received', [
            'gateway' => $this->gatewayName,
            'notification_type' => $decodedPayload['notificationType'] ?? 'unknown',
            'original_transaction_id' => $decodedPayload['data']['originalTransactionId'] ?? null,
        ]);

        event(new WebhookReceived(
            gateway: $this->gatewayName,
            payload: $decodedPayload,
            headers: $request->headers->all()
        ));

        try {
            // Pass the fully decoded and verified payload to the gateway
            return $this->process($request->duplicate([], $decodedPayload));
        } catch (Exception $e) {
            Log::error('Apple webhook processing failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'payload' => $originalPayload,
                'decoded_payload' => $decodedPayload,
            ]);

            // Always return 200 to prevent Apple retries
            return response()->noContent(200);
        }
    }
}