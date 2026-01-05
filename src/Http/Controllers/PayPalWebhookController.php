<?php

namespace Imannms000\LaravelUnifiedSubscriptions\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Imannms000\LaravelUnifiedSubscriptions\Enums\Gateway;
use Imannms000\LaravelUnifiedSubscriptions\Gateways\PayPalGateway;
use Imannms000\LaravelUnifiedSubscriptions\Http\Requests\PayPalWebhookRequest;

class PayPalWebhookController extends WebhookController
{
    protected function resolveGatewayName(): string
    {
        return Gateway::PAYPAL->value;
    }

    /**
     * Handle incoming PayPal webhook
     */
    public function handle(PayPalWebhookRequest $request): Response
    {
        $payload = $request->json()->all();
        $headers = $request->headers->all();

        Log::info('PayPal webhook received', [
            'event_type' => $payload['event_type'] ?? 'unknown',
            'resource_type' => $payload['resource_type'] ?? 'unknown',
            'transmission_id' => $request->header('Paypal-Transmission-Id'),
        ]);

        // Fire generic webhook event for monitoring
        event(new \Imannms000\LaravelUnifiedSubscriptions\Events\WebhookReceived(
            gateway: $this->gatewayName(),
            payload: $payload,
            headers: $headers
        ));

        // Critical: Verify webhook signature before any processing
        /** @var PayPalGateway $gateway */
        $gateway = $this->gateway;

        if (! $gateway->verifyWebhookSignature($request)) {
            Log::warning('Invalid PayPal webhook signature', [
                'transmission_id' => $request->header('Paypal-Transmission-Id'),
                'headers' => $headers,
                'payload_summary' => [
                    'id' => $payload['id'] ?? null,
                    'event_type' => $payload['event_type'] ?? null,
                ],
            ]);

            return response('Invalid signature', 401);
        }

        // Signature valid → pass to parent (which will call gateway->handleWebhook())
        return $this->process($request);
    }

    /**
     * Helper to get gateway name consistently
     */
    protected function gatewayName(): string
    {
        return $this->resolveGatewayName();
    }
}