<?php

namespace Imannms000\LaravelUnifiedSubscriptions\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Http\Request;

class WebhookReceived
{
    use Dispatchable;

    public string $gateway;
    public array $payload;
    public array $headers;

    public function __construct(string $gateway, array $payload, array $headers = [])
    {
        $this->gateway = $gateway;
        $this->payload = $payload;
        $this->headers = $headers;
    }
}