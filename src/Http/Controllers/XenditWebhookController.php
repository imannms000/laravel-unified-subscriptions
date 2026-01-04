<?php

namespace Imannms000\LaravelUnifiedSubscriptions\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Imannms000\LaravelUnifiedSubscriptions\Enums\Gateway;
use Imannms000\LaravelUnifiedSubscriptions\Http\Requests\XenditWebhookRequest;

class XenditWebhookController extends WebhookController
{
    protected function resolveGatewayName(): string
    {
        return Gateway::XENDIT->value;
    }

    public function handle(Request $request): Response
    {
        $token = $request->header('x-callback-token');
        $callbackToken = config('subscription.gateways.xendit.callback_token');

        if ($token !== $callbackToken) {
            Log::warning('Invalid Xendit webhook token', [
                'received_token' => $token,
                'ip' => $request->ip(),
                'payload' => $request->all()
            ]);

            // Return 200 to stop retries, but don't process
            return response('OK', 200);
        }

        Log::info('Valid Xendit webhook received', [
            'event' => $request->input('event'),
            'reference_id' => $request->input('data.reference_id'),
            'payload' => $request->all(),
        ]);

        return parent::handle($request);
    }
}