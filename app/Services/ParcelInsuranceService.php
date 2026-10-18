<?php

namespace App\Services;

use App\Models\ParcelNature;

/**
 * Supplément d'assurance d'un colis Diaspo (avion et maritime) : seule source
 * du calcul, pour l'aperçu dans l'app comme pour le montant encaissé.
 *
 * supplément = max(valeur déclarée × taux de la nature, minimum de la nature)
 *
 * La valeur déclarée et le supplément sont dans la devise de l'offre (la
 * valeur saisie dans la devise de l'expéditeur est convertie d'abord, voir
 * declaredValueIn) ; le minimum, saisi en XAF dans l'admin, est converti aussi.
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

    /**
     * Valeur déclarée ramenée dans la devise de l'offre. L'expéditeur la saisit
     * dans sa propre devise ([$from], ex. XAF) : on la convertit, comme les
     * autres montants, avant de calculer et d'enregistrer l'assurance.
     *
     * @throws \RuntimeException si le taux est inconnu
     */
    public static function declaredValueIn(float $value, ?string $from, string $to): float
    {
        $from = strtoupper((string) $from);
        $to = strtoupper($to);
        if ($from === '' || $from === $to) {
            return round($value, 2);
        }

        $converted = ExchangeRateService::convertAmount($from, $to, $value);
        if ($converted === null) {
            throw new \RuntimeException(__('payments.rate_unavailable', ['currency' => $from, 'pivot' => $to]));
        }

        return round($converted, 2);
    }
}
