<?php

namespace Imannms000\LaravelUnifiedSubscriptions\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Log;

class XenditWebhookRequest extends WebhookRequest
{
    public function authorize(): bool
    {
        return true; // Auth handled in controller
    }

    public function rules(): array
    {
        return [
            'event' => 'required|string|in:recurring.plan.activated,recurring.plan.inactivated',
            'data' => 'required|array',
            'data.reference_id' => 'required|string', // sub-{id}
            'data.id' => 'sometimes|string', // plan ID
            'data.status' => 'sometimes|string|in:ACTIVE,INACTIVE,PENDING',
            'data.amount' => 'sometimes|numeric',
            'data.currency' => 'sometimes|string|size:3',
        ];
    }

    protected function failedValidation(\Illuminate\Contracts\Validation\Validator $validator)
    {
        Log::warning('Xendit webhook validation failed', [
            'errors' => $validator->errors()->toArray(),
            'payload' => $this->all(),
        ]);

        // Return 200 to acknowledge
        abort(200, 'OK');
    }
}