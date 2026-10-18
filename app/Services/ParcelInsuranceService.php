<?php

namespace App\Services;

use App\Models\ParcelNature;

/**
 * Supplément d'assurance d'un colis Diaspo (avion et maritime) : seule source
 * du calcul, pour l'aperçu dans l'app comme pour le montant encaissé.
 *
 * supplément = valeur déclarée × taux de la nature
 *
 * La valeur déclarée et le supplément sont dans la devise de l'offre (la
 * valeur saisie dans la devise de l'expéditeur est convertie d'abord, voir
 * declaredValueIn).
 */
class ParcelInsuranceService
{
    /**
     * @return float supplément dans $currency
     */
    public static function quote(ParcelNature $nature, float $declaredValue): float
    {
        return round($declaredValue * (float) $nature->insurance_rate / 100, 2);
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
