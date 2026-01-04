<?php

namespace Imannms000\LaravelUnifiedSubscriptions\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Response;

/**
 * Base FormRequest for all payment gateway webhooks
 *
 * Responsibilities:
 * - Public endpoint (no auth needed — security via signature/token)
 * - Early payload validation and transformation (decoding, verification)
 * - Always return 200 OK to prevent provider retries
 * - Structured logging on validation failure
 */
abstract class WebhookRequest extends FormRequest
{
    /**
     * Webhooks are public endpoints.
     * Authentication is handled via signature, token, or IP allowlist.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Define validation rules in child classes.
     */
    abstract public function rules(): array;

    /**
     * Optional: Override in child classes for payload preprocessing
     * (e.g., base64 decoding for Google, JWS verification for Apple)
     */
    protected function prepareForValidation(): void
    {
        // Child classes implement decoding/verification here
    }

    /**
     * Custom failure handling:
     * - Log the error
     * - Return 200 OK (critical — most providers retry on non-2xx)
     */
    protected function failedValidation(\Illuminate\Contracts\Validation\Validator $validator)
    {
        Log::warning('Webhook validation failed', [
            'gateway' => $this->route()?->getName() ?? 'unknown',
            'errors' => $validator->errors()->toArray(),
            'payload' => $this->all(),
            'ip' => $this->ip(),
            'headers' => $this->headers->all(),
        ]);

        // Apple, Google, PayPal, Xendit — all retry aggressively on non-200
        // So we respond with 200 even on validation failure
        abort(Response::HTTP_OK, 'OK');
    }

    /**
     * Optional: Custom success response
     * Most gateways expect no content or just 200
     */
    protected function passedValidation(): void
    {
        // Child classes can override if needed
    }
}