<?php

namespace Imannms000\LaravelUnifiedSubscriptions\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class VerifyAppleSubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'plan_id' => [
                'required',
                'integer',
                Rule::exists('plans', 'id')->where('active', true),
            ],
            'receipt_data' => [
                'required',
                'string',
                'min:10', // base64 receipts are long
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'plan_id.exists' => 'The selected plan is invalid or inactive.',
            'receipt_data.required' => 'App Store receipt is required.',
        ];
    }

    protected function failedValidation(\Illuminate\Contracts\Validation\Validator $validator)
    {
        throw new \Illuminate\Validation\ValidationException($validator, response()->json([
            'message' => 'Validation failed',
            'errors' => $validator->errors(),
        ], 422));
    }
}