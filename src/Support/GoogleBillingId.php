<?php

namespace Imannms000\LaravelUnifiedSubscriptions\Support;

use Hashids\Hashids;
use Illuminate\Support\Facades\Log;

class GoogleBillingId
{
    /**
     * Get the configured Hashids instance.
     */
    private static function getEngine(): Hashids
    {
        return new Hashids(
            config('subscription.gateways.google.obfuscation_salt', 'stable-salt'),
            64,
            'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ1234567890'
        );
    }

    /**
     * Encodes a User ID for Google Play.
     */
    public static function encode(int|string $userId): string
    {
        return self::getEngine()->encode($userId);
    }

    /**
     * Decodes a Google string back to a User ID.
     */
    public static function decode(string $obfuscatedId): ?int
    {
        $decoded = self::getEngine()->decode($obfuscatedId);

        if (empty($decoded)) {
            Log::warning('Invalid or tampered Google obfuscatedAccountId received', [
                'obfuscated_id' => $obfuscatedId,
            ]);
            return null;
        }

        return $decoded[0];
    }
}