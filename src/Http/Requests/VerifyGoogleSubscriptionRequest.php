<?php

namespace Imannms000\LaravelUnifiedSubscriptions\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class VerifyGoogleSubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Only authenticated users can verify purchases
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
            'purchase_token' => [
                'required',
                'string',
                'min:10',
            ],
            'package_name' => [
                'sometimes',
                'required',
                'string',
                'in:' . config('subscription.gateways.google.package_name'),
            ],
        ];
    }

    public function failedValidation(\Illuminate\Contracts\Validation\Validator $validator)
    {
        throw new \Illuminate\Http\Exceptions\HttpResponseException(
            response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422)
        );
    }

    protected function prepareForValidation(): void
    {
        // Optional: normalize input
        $this->merge([
            'plan_id' => $this->plan_id ? $this->plan_id : null,
        ]);
    }
}