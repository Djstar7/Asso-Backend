<?php

namespace App\Helpers;

class CurrencyHelper
{
    /**
     * Convert amount from one currency to another
     *
     * @param float $amount
     * @param string $fromCurrency
     * @param string $toCurrency
     * @return float
     */
    public static function convert(float $amount, string $fromCurrency, string $toCurrency = 'FCFA'): float
    {
        // If currencies are the same, no conversion needed
        if (strtoupper($fromCurrency) === strtoupper($toCurrency)) {
            return $amount;
        }

        $rates = config('currency.rates', []);
        $baseCurrency = config('currency.base', 'FCFA');

        // Get rates for both currencies
        $fromRate = $rates[strtoupper($fromCurrency)] ?? 1.0;
        $toRate = $rates[strtoupper($toCurrency)] ?? 1.0;

        // Convert to base currency (FCFA) first, then to target currency
        if (strtoupper($fromCurrency) === $baseCurrency) {
            // From FCFA to target currency
            return $amount / $toRate;
        } elseif (strtoupper($toCurrency) === $baseCurrency) {
            // From source currency to FCFA
            return $amount * $fromRate;
        } else {
            // Convert through FCFA (base currency)
            $inFcfa = $amount * $fromRate;
            return $inFcfa / $toRate;
        }
    }

    /**
     * Convert EUR to FCFA (most common conversion)
     *
     * @param float $amountInEur
     * @return float
     */
    public static function eurToFcfa(float $amountInEur): float
    {
        return self::convert($amountInEur, 'EUR', 'FCFA');
    }

    /**
     * Convert FCFA to EUR
     *
     * @param float $amountInFcfa
     * @return float
     */
    public static function fcfaToEur(float $amountInFcfa): float
    {
        return self::convert($amountInFcfa, 'FCFA', 'EUR');
    }

    /**
     * Format amount with currency symbol
     *
     * @param float $amount
     * @param string $currency
     * @return string
     */
    public static function format(float $amount, string $currency = 'FCFA'): string
    {
        $currency = strtoupper($currency);

        switch ($currency) {
            case 'EUR':
                return number_format($amount, 2, ',', ' ') . ' €';
            case 'FCFA':
            case 'XOF':
                return number_format($amount, 0, ',', ' ') . ' FCFA';
            default:
                return number_format($amount, 2, ',', ' ') . ' ' . $currency;
        }
    }
}
