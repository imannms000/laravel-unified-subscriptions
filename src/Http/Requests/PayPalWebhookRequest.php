<?php

// src/Http/Requests/PayPalWebhookRequest.php

namespace Imannms000\LaravelUnifiedSubscriptions\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Log;

class PayPalWebhookRequest extends WebhookRequest
{
    public function authorize(): bool
    {
        return true; // Authorization handled via signature verification
    }

    public function rules(): array
    {
        return [
            'id' => 'required|string',
            'event_type' => 'required|string',
            'event_version' => 'sometimes|string',
            'resource_type' => 'sometimes|string',
            'resource' => 'required|array',
            'resource_version' => 'sometimes|string',
            'summary' => 'sometimes|string',
            'create_time' => 'required|string|date_format:Y-m-d\TH:i:s\Z',
        ];
    }

    protected function failedValidation(\Illuminate\Contracts\Validation\Validator $validator)
    {
        Log::warning('PayPal webhook validation failed', [
            'errors' => $validator->errors()->toArray(),
            'payload' => $this->all(),
            'headers' => $this->headers->all(),
        ]);

        // PayPal expects 2xx to stop retries → return 200 even on validation fail
        abort(200, 'Validation failed');
    }
}