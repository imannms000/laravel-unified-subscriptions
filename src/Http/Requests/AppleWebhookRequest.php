<?php

namespace Imannms000\LaravelUnifiedSubscriptions\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Log;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Exception;

class AppleWebhookRequest extends WebhookRequest
{
    protected string $rootCertPath;

    public function __construct(array $query = [], array $request = [], array $attributes = [], array $cookies = [], array $files = [], array $server = [], $content = null)
    {
        parent::__construct($query, $request, $attributes, $cookies, $files, $server, $content);
        $this->rootCertPath = storage_path('app/apple_root.pem');
    }

    public function rules(): array
    {
        return [
            'signedPayload' => 'required|string',
        ];
    }

    protected function prepareForValidation(): void
    {
        $input = $this->json()->all();

        if (!isset($input['signedPayload'])) {
            return;
        }

        try {
            $decoded = $this->verifyAndDecodeSignedPayload($input['signedPayload']);
            $this->merge($decoded);
        } catch (Exception $e) {
            Log::warning('Apple webhook signature verification failed', [
                'error' => $e->getMessage(),
            ]);
            // Do not merge - validation will fail
        }
    }

    protected function failedValidation(\Illuminate\Contracts\Validation\Validator $validator)
    {
        Log::warning('Apple webhook validation failed', [
            'errors' => $validator->errors()->toArray(),
            'payload' => $this->json()->all(),
        ]);

        // Always return 200 - Apple will retry on non-2xx
        abort(200);
    }

    /**
     * Verify and decode Apple's JWS signedPayload using full x5c chain and local root
     */
    protected function verifyAndDecodeSignedPayload(string $signedPayload): array
    {
        // Split JWS
        $parts = explode('.', $signedPayload);
        if (count($parts) !== 3) {
            throw new Exception('Invalid JWS format');
        }

        [$headerB64, $payloadB64, $signatureB64] = $parts;

        $header = json_decode(base64_decode($headerB64), true);
        if (!isset($header['x5c']) || count($header['x5c']) < 1) {
            throw new Exception('Missing x5c certificate chain');
        }

        $leafCertPem = $this->certToPem($header['x5c'][0]);

        // Extract public key from leaf certificate
        $certResource = openssl_x509_read($leafCertPem);
        if (!$certResource) {
            throw new Exception('Failed to read leaf certificate');
        }

        $publicKey = openssl_pkey_get_public($certResource);
        if (!$publicKey) {
            throw new Exception('Failed to extract public key');
        }

        $publicKeyPem = $this->exportPublicKey($publicKey);

        // Verify chain against Apple's Root CA - G3
        $rootPem = file_get_contents($this->rootCertPath);
        if ($rootPem === false) {
            throw new Exception('Unable to load Apple Root CA - G3 certificate');
        }

        $verifyResult = openssl_x509_verify($leafCertPem, $rootPem);
        if ($verifyResult !== 1) {
            $error = openssl_error_string();
            throw new Exception("Certificate chain verification failed: {$error}");
        }

        // Decode JWT
        $algorithm = $header['alg'] ?? 'ES256';
        $decoded = JWT::decode($signedPayload, new Key($publicKeyPem, $algorithm));

        return (array) $decoded;
    }

    protected function certToPem(string $cert): string
    {
        return "-----BEGIN CERTIFICATE-----\n" .
               chunk_split($cert, 64, "\n") .
               "-----END CERTIFICATE-----\n";
    }

    protected function exportPublicKey($publicKey): string
    {
        $details = openssl_pkey_get_details($publicKey);
        return $details['key'] ?? '';
    }
}