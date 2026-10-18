<?php

namespace App\Services;

use App\Models\ParcelNature;

/**
 * Supplément d'assurance d'un colis Diaspo (avion et maritime) : seule source
 * du calcul, pour l'aperçu dans l'app comme pour le montant encaissé.
 *
 * supplément = max(valeur déclarée × taux de la nature, minimum de la nature)
 *
 * La valeur déclarée et le supplément sont dans la devise de l'offre ; le
 * minimum, saisi en XAF dans l'admin, est converti dans cette devise.
 */
class ParcelInsuranceService
{
    /**
     * @return float supplément dans $currency
     * @throws \RuntimeException si le minimum ne peut être converti
     */
    public static function quote(ParcelNature $nature, float $declaredValue, string $currency): float
    {
        $currency = strtoupper($currency);
        $byRate = $declaredValue * (float) $nature->insurance_rate / 100;

        $minimum = (float) $nature->min_insurance_amount;
        if ($minimum > 0 && $currency !== PaymentMethodService::PIVOT) {
            $converted = ExchangeRateService::convertAmount(PaymentMethodService::PIVOT, $currency, $minimum);
            if ($converted === null) {
                throw new \RuntimeException(__('payments.rate_unavailable', ['currency' => $currency, 'pivot' => PaymentMethodService::PIVOT]));
            }
            $minimum = $converted;
        }

        return round(max($byRate, $minimum), 2);
    }
}
