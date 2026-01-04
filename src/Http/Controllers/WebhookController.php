<?php

namespace Imannms000\LaravelUnifiedSubscriptions\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Imannms000\LaravelUnifiedSubscriptions\Contracts\GatewayInterface;
use Imannms000\LaravelUnifiedSubscriptions\Facades\SubscriptionGateway;
use Imannms000\LaravelUnifiedSubscriptions\Events\WebhookReceived;
use Illuminate\Support\Facades\Log;
use Illuminate\Foundation\Http\FormRequest;
use Imannms000\LaravelUnifiedSubscriptions\Http\Requests\WebhookRequest;

abstract class WebhookController
{
    protected string $gatewayName;

    protected GatewayInterface $gateway;

    public function __construct()
    {
        $this->gatewayName = $this->resolveGatewayName();
        $this->gateway = SubscriptionGateway::driver($this->gatewayName);
    }

    abstract protected function resolveGatewayName(): string;

    public function handle(Request $request): Response
    {
        // Fire generic event for monitoring
        event(new WebhookReceived($this->gatewayName, $request->all()));

        // Log for debugging
        Log::info("Webhook received from {$this->gatewayName}", $request->all());

        try {

            $this->gateway->handleWebhook($request);

            return response()->noContent(200);
        } catch (\Throwable $e) {
            Log::error("Webhook processing failed for {$this->gatewayName}", [
                'exception' => $e->getMessage(),
                'payload' => $request->all(),
            ]);

            // Some gateways require 2xx to stop retries
            return response()->noContent(200);
        }
    }
}