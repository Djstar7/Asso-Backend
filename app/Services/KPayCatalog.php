<?php

namespace App\Services;

/**
 * Catalogue KPay côté serveur : correspondance code opérateur → devise / pays.
 * Le retrait reste dans le pays de l'opérateur (pas de payout cross-country).
 * Miroir de lib/app/core/values/kpay_catalog.dart (mobile).
 */
class KPayCatalog
{
    /** provider code => ['currency' => 'XAF', 'country' => 'CMR'] */
    public const PROVIDERS = [
        // XOF (UEMOA)
        'MTN_MOMO_BEN' => ['currency' => 'XOF', 'country' => 'BEN'],
        'MOOV_BEN' => ['currency' => 'XOF', 'country' => 'BEN'],
        'MTN_MOMO_CIV' => ['currency' => 'XOF', 'country' => 'CIV'],
        'ORANGE_CIV' => ['currency' => 'XOF', 'country' => 'CIV'],
        'FREE_SEN' => ['currency' => 'XOF', 'country' => 'SEN'],
        'ORANGE_SEN' => ['currency' => 'XOF', 'country' => 'SEN'],
        // XAF (CEMAC)
        'MTN_MOMO_CMR' => ['currency' => 'XAF', 'country' => 'CMR'],
        'ORANGE_CMR' => ['currency' => 'XAF', 'country' => 'CMR'],
        'AIRTEL_GAB' => ['currency' => 'XAF', 'country' => 'GAB'],
        'AIRTEL_COG' => ['currency' => 'XAF', 'country' => 'COG'],
        'MTN_MOMO_COG' => ['currency' => 'XAF', 'country' => 'COG'],
        // Autres
        'VODACOM_MPESA_COD' => ['currency' => 'CDF', 'country' => 'COD'],
        'AIRTEL_COD' => ['currency' => 'CDF', 'country' => 'COD'],
        'ORANGE_COD' => ['currency' => 'CDF', 'country' => 'COD'],
        'MPESA_KEN' => ['currency' => 'KES', 'country' => 'KEN'],
        'AIRTEL_RWA' => ['currency' => 'RWF', 'country' => 'RWA'],
        'MTN_MOMO_RWA' => ['currency' => 'RWF', 'country' => 'RWA'],
        'ORANGE_SLE' => ['currency' => 'SLE', 'country' => 'SLE'],
        'AIRTEL_OAPI_UGA' => ['currency' => 'UGX', 'country' => 'UGA'],
        'MTN_MOMO_UGA' => ['currency' => 'UGX', 'country' => 'UGA'],
        'AIRTEL_OAPI_ZMB' => ['currency' => 'ZMW', 'country' => 'ZMB'],
        'MTN_MOMO_ZMB' => ['currency' => 'ZMW', 'country' => 'ZMB'],
        'ZAMTEL_ZMB' => ['currency' => 'ZMW', 'country' => 'ZMB'],
    ];

    /** Devise déduite du code opérateur (XAF par défaut si inconnu). */
    public static function currencyForProvider(?string $provider): string
    {
        return self::PROVIDERS[$provider]['currency'] ?? 'XAF';
    }

    /** Pays ISO3 déduit du code opérateur. */
    public static function countryForProvider(?string $provider): ?string
    {
        return self::PROVIDERS[$provider]['country'] ?? null;
    }

    public static function isValidProvider(?string $provider): bool
    {
        return $provider !== null && isset(self::PROVIDERS[$provider]);
    }
}
