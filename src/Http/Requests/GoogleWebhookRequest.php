<?php

namespace Imannms000\LaravelUnifiedSubscriptions\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Imannms000\LaravelUnifiedSubscriptions\Http\Requests\WebhookRequest;

class GoogleWebhookRequest extends WebhookRequest
{
    public function rules(): array
    {
        return [
            // Pub/Sub wrapper (for production)
            'message.data' => 'sometimes|required_if:has_message,true|string',
            'message.messageId' => 'sometimes|required_if:has_message,true|string',
            'message.publishTime' => 'sometimes|required_if:has_message,true|string|date',
            
            // Direct developer notification (for testing)
            'subscriptionNotification' => 'sometimes|required_without:message|array',
            'subscriptionNotification.purchaseToken' => 'sometimes|required|string',
            'subscriptionNotification.notificationType' => 'sometimes|required|integer|between:1,13',
            'subscriptionNotification.subscriptionId' => 'sometimes|required|string',
        ];
    }

    protected function prepareForValidation(): void
    {
        $input = $this->json()->all();

        // Handle Pub/Sub format (production)
        if (isset($input['message']['data'])) {
            $decodedData = base64_decode($input['message']['data'], true);
            
            if ($decodedData === false) {
                Log::warning('Failed to base64 decode Google Pub/Sub data', ['data' => $input['message']['data']]);
                return;
            }

            $decodedNotification = json_decode($decodedData, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::warning('Failed to JSON decode Google notification', [
                    'error' => json_last_error_msg(),
                    'data' => $decodedData
                ]);
                return;
            }

            // Merge decoded notification into request (highest priority)
            $this->merge($decodedNotification);
        }

        // Handle direct test notifications (no Pub/Sub wrapper)
        if (isset($input['subscriptionNotification'])) {
            $this->merge($input);
        }
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $payload = $this->validated();
            
            // Must have either Pub/Sub data or direct subscriptionNotification
            if (!isset($payload['message']['data']) && !isset($payload['subscriptionNotification'])) {
                $validator->errors()->add('payload', 'Invalid Google Play notification format. Expected Pub/Sub or direct subscriptionNotification.');
            }

            // Validate notificationType if present
            if (isset($payload['subscriptionNotification']['notificationType'])) {
                $type = $payload['subscriptionNotification']['notificationType'];
                $validTypes = [1,2,3,4,5,6,7,8,9,10,11,12,13]; // All RTDN types
                
                if (!in_array($type, $validTypes)) {
                    $validator->errors()->add('notificationType', 'Invalid notification type: ' . $type);
                }
            }
        });
    }

    /**
     * Always return 200 to Google to acknowledge receipt (prevents retries)
     */
    protected function failedValidation(\Illuminate\Contracts\Validation\Validator $validator)
    {
        Log::warning('Google Play webhook validation failed', [
            'errors' => $validator->errors()->toArray(),
            'payload' => $this->all(),
            'headers' => $this->headers->all(),
        ]);

        // Return 200 anyway - Google retries on non-2xx, validation failures shouldn't block
        abort(200, 'OK');
    }
}