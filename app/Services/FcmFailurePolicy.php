<?php

namespace App\Services;

class FcmFailurePolicy
{
    public static function classify(\Throwable $error): array
    {
        $details = method_exists($error, 'errors') ? $error->errors() : [];
        $code = null;
        array_walk_recursive($details, function ($value, $key) use (&$code) {
            if ($key === 'errorCode') {
                $code = strtoupper((string) $value);
            }
        });
        $code ??= $details['error']['status'] ?? null;
        if (! $code && $error instanceof \Kreait\Firebase\Exception\Messaging\NotFound) {
            $code = 'UNREGISTERED';
        }
        $code ??= 'UNAVAILABLE';

        // INVALID_ARGUMENT may describe a malformed payload, not a revoked token.
        return ['error_code' => $code, 'invalid_token' => $code === 'UNREGISTERED',
            'retryable' => in_array($code, ['UNAVAILABLE', 'INTERNAL', 'QUOTA_EXCEEDED', 'THIRD_PARTY_AUTH_ERROR', 'UNAUTHENTICATED', 'NOT_CONFIGURED'], true)];
    }
}
