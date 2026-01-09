<?php

namespace Imannms000\LaravelUnifiedSubscriptions\Gateways;

use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Imannms000\LaravelUnifiedSubscriptions\Contracts\GatewayInterface;
use Imannms000\LaravelUnifiedSubscriptions\Models\Subscription;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Exception;
use Carbon\Carbon;
use Imannms000\LaravelUnifiedSubscriptions\Enums\Gateway;

class AppleGateway extends AbstractGateway implements GatewayInterface
{
    protected string $environment;

    protected string $rootCertPath;

    public function __construct()
    {
        parent::__construct();
        $this->environment = config('subscription.gateways.apple.sandbox') ? 'sandbox' : 'production';
        $this->rootCertPath = storage_path('app/apple_root.pem'); // Assume stored here; publishable
    }

    public function getName(): string
    {
        return Gateway::APPLE->value;
    }

    protected function getVerifyUrl(): string
    {
        return $this->environment === 'sandbox'
            ? 'https://sandbox.itunes.apple.com/verifyReceipt'
            : 'https://buy.itunes.apple.com/verifyReceipt';
    }

    public function createSubscription(Subscription $subscription, array $options = []): mixed
    {
        $receipt = $options['receipt_data'] ?? null;

        if (! $receipt) {
            throw new Exception('Receipt data is required for Apple subscription validation.');
        }

        $response = Http::post($this->getVerifyUrl(), [
            'receipt-data' => $receipt,
            'password' => config('subscription.gateways.apple.shared_secret'),
            'exclude-old-transactions' => true,
        ])->json();

        if ($response['status'] !== 0) {
            throw new Exception('Invalid receipt: ' . ($response['status'] ?? 'Unknown error'));
        }

        $latestReceiptInfo = collect($response['latest_receipt_info'] ?? [])->last();

        if (!$latestReceiptInfo) {
            throw new Exception('No latest receipt info found in response.');
        }

        $expiresMs = $latestReceiptInfo['expires_date_ms'] ?? null;
        $endsAt = $expiresMs ? Carbon::createFromTimestampMs($expiresMs) : null;

        $gatewayId = $latestReceiptInfo['original_transaction_id'] ?? $latestReceiptInfo['transaction_id'];

        $this->markSubscriptionAsActive($subscription, $gatewayId, $endsAt, $response);

        // Record initial transaction
        $price = $subscription->plan->getPriceForGateway(Gateway::APPLE->value);
        $currency = $subscription->plan->getCurrencyForGateway(Gateway::APPLE->value);

        $subscription->recordTransaction(
            type: 'payment',
            amount: $price,
            currency: $currency,
            gatewayTransactionId: $latestReceiptInfo['transaction_id'],
            metadata: $latestReceiptInfo
        );

        return $response;
    }

    public function redirectToCheckout(Subscription $subscription): ?RedirectResponse
    {
        return null; // In-app purchase handled client-side
    }

    public function cancelSubscription(Subscription $subscription): void
    {
        // Apple does not support server-side cancellation; mark locally based on notifications
        Log::info('Apple subscription cancel requested locally', ['subscription_id' => $subscription->id]);
        $this->markSubscriptionAsCanceled($subscription);
    }

    public function resumeSubscription(Subscription $subscription): void
    {
        // Resume handled via user repurchase; mark locally if notified
        $subscription->resume();
    }

    public function swapPlan(Subscription $subscription, $newPlanId): void
    {
        // Plan changes require new purchase; no server-side action
        throw new Exception('Plan swap must be handled via new in-app purchase on Apple.');
    }

    public function handleWebhook(Request $request): void
    {
        $payload = $request->json()->all();

        if (!isset($payload['signedPayload'])) {
            Log::warning('Invalid Apple webhook: No signedPayload', ['payload' => $payload]);
            return;
        }

        try {
            $decodedNotification = $this->decodeSignedPayload($payload['signedPayload']);

            $notificationType = $decodedNotification['notificationType'] ?? null;
            $data = $decodedNotification['data'] ?? [];

            // Decode signedTransactionInfo (another JWS)
            $signedTransactionInfo = $data['signedTransactionInfo'] ?? null;
            if ($signedTransactionInfo) {
                $decodedTransaction = $this->decodeSignedPayload($signedTransactionInfo, isTransaction: true);
            } else {
                $decodedTransaction = [];
            }

            $originalTransactionId = $decodedTransaction['originalTransactionId'] ?? null;

            $subscription = Subscription::where('gateway', Gateway::APPLE->value)
                ->where('gateway_id', $originalTransactionId)
                ->first();

            if (!$subscription) {
                Log::info('Apple webhook: Subscription not found', ['originalTransactionId' => $originalTransactionId]);
                return;
            }

            // Handle common types
            match ($notificationType) {
                'SUBSCRIBED', 'DID_RENEW' => $this->handleRenewOrSubscribe($subscription, $decodedTransaction),
                'DID_FAIL_TO_RENEW' => $this->handleFailedRenew($subscription, $decodedTransaction),
                'CANCEL', 'DID_CHANGE_RENEWAL_STATUS', 'EXPIRED' => $this->markSubscriptionAsCanceled($subscription),
                'BILLING_RECOVERY' => $subscription->resume(),
                default => Log::info('Unhandled Apple notification type', ['type' => $notificationType]),
            };

            // Always sync ends_at from latest transaction
            $expiresMs = $decodedTransaction['expiresDate'] ?? null;
            $endsAt = $expiresMs ? Carbon::createFromTimestampMs($expiresMs) : null;
            $subscription->update(['ends_at' => $endsAt]);
        } catch (Exception $e) {
            Log::error('Apple webhook processing failed', [
                'error' => $e->getMessage(),
                'payload' => $payload,
            ]);
        }
    }

    protected function handleRenewOrSubscribe(Subscription $subscription, array $decodedTransaction): void
    {
        $endsAt = $decodedTransaction['expiresDate'] ? Carbon::createFromTimestampMs($decodedTransaction['expiresDate']) : null;
        $this->markSubscriptionAsRenewed($subscription, $endsAt, $decodedTransaction);

        $price = $decodedTransaction['price'] ?? $subscription->plan->getPriceForGateway(Gateway::APPLE->value);
        $currency = $decodedTransaction['currency'] ?? $subscription->plan->getCurrencyForGateway(Gateway::APPLE->value);

        $subscription->recordTransaction(
            type: 'renewal',
            amount: $price / 1000, // Apple price is in smallest unit
            currency: $currency,
            gatewayTransactionId: $decodedTransaction['transactionId'],
            metadata: $decodedTransaction
        );
    }

    protected function handleFailedRenew(Subscription $subscription, array $decodedTransaction): void
    {
        $subscription->recordTransaction(
            type: 'failed',
            amount: 0,
            currency: $subscription->plan->getCurrencyForGateway(Gateway::APPLE->value),
            status: 'failed',
            metadata: $decodedTransaction
        );
    }

    /**
     * Decode and verify Apple JWS signedPayload
     */
    protected function decodeSignedPayload(string $signedPayload, bool $isTransaction = false): array
    {
        [$headerB64, $payloadB64, $signatureB64] = explode('.', $signedPayload);

        $header = json_decode(base64_decode($headerB64), true);
        $x5c = $header['x5c'] ?? [];

        if (count($x5c) < 3) {
            throw new Exception('Invalid certificate chain in Apple JWS header');
        }

        // Reformat certs to PEM
        $leafCert = "-----BEGIN CERTIFICATE-----\n" . chunk_split($x5c[0], 64, "\n") . "-----END CERTIFICATE-----";
        $intermediateCert = "-----BEGIN CERTIFICATE-----\n" . chunk_split($x5c[1], 64, "\n") . "-----END CERTIFICATE-----";
        $rootCert = "-----BEGIN CERTIFICATE-----\n" . chunk_split($x5c[2], 64, "\n") . "-----END CERTIFICATE-----";

        // Load local Apple root PEM
        $localRootPem = file_get_contents($this->rootCertPath);
        if ($localRootPem === false) {
            throw new Exception('Unable to load Apple root certificate');
        }

        // Verify chain (root matches local)
        if (openssl_x509_verify($rootCert, $localRootPem) !== 1) {
            throw new Exception('Apple root certificate verification failed');
        }

        // Verify intermediate signed by root
        if (openssl_x509_verify($intermediateCert, $rootCert) !== 1) {
            throw new Exception('Intermediate certificate verification failed');
        }

        // Verify leaf signed by intermediate
        if (openssl_x509_verify($leafCert, $intermediateCert) !== 1) {
            throw new Exception('Leaf certificate verification failed');
        }

        // Extract public key from leaf
        $certObj = openssl_x509_read($leafCert);
        $pubKeyObj = openssl_pkey_get_public($certObj);
        $pubKeyDetails = openssl_pkey_get_details($pubKeyObj);
        $publicKey = $pubKeyDetails['key'];

        // Decode JWT with verification
        $algorithm = $header['alg'] ?? 'ES256';

        $decoded = JWT::decode($signedPayload, new Key($publicKey, $algorithm));

        return (array) $decoded;
    }
}