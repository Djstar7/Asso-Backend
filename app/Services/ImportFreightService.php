<?php

namespace App\Services;

use App\Models\ImportShippingOption;
use App\Models\Product;
use App\Models\ProductPriceTier;
use App\Models\ProductVariant;
use App\Models\Setting;
use App\Support\ImportHub;

/**
 * Transport d'une commande en gros jusqu'à Douala, calculé par le serveur seul.
 * Bateau : CBM des cartons × tarif, sans le poids ; une ligne sans aucun volume
 * connu compte 1 CBM par 1 000 kg. Avion : poids réel + poids volumétrique ; le
 * plafond (100 kg) lit le poids réel. Au-delà, la fiche doit donner un CBM.
 */
class ImportFreightService
{
    private const KG_PER_CBM = 1000;

    private const VOLUMETRIC_DIVISOR = 6000;

    public const DEFAULT_AIR_MAX_KG = 100;

    public const AIR_MAX_SETTING = 'import_air_max_weight_kg';

    /** Au-delà de ce poids réel, tous pays confondus, seul le bateau est proposé. */
    public static function airMaxWeightKg(): float
    {
        return (float) Setting::get(self::AIR_MAX_SETTING, self::DEFAULT_AIR_MAX_KG);
    }


    /**
     * @param  iterable<array{product: Product, tier: ?ProductPriceTier, variant?: ?ProductVariant, quantity: int}>  $lines
     */
    public function measure(iterable $lines): array
    {
        $weight = 0.0;
        $cbm = 0.0;
        $cbmFromWeight = 0.0;
        $missingWeight = false;
        $unmeasured = false;
        foreach ($lines as $line) {
            $quantity = (int) $line['quantity'];
            $unitWeight = $line['product']->packageWeightKg($line['tier']);
            // La taille choisie par le client prime sur le CBM déclaré, le palier et la fiche.
            $dimensions = ($line['variant'] ?? null)?->packageDimensions();
            $lineCbm = $dimensions
                ? $dimensions[0] * $dimensions[1] * $dimensions[2] / 1_000_000 * $quantity
                : $line['product']->packageVolumeCbm($line['tier'], $quantity);
            $missingWeight = $missingWeight || ! $unitWeight;
            $unmeasured = $unmeasured || (! $unitWeight && $lineCbm === null);
            $weight += ($unitWeight ?? 0) * $quantity;
            if ($lineCbm !== null) {
                $cbm += $lineCbm;
            } else {
                // Aucun volume connu (fiche de moins de 100 kg sans CBM) : 1 CBM par 1 000 kg.
                $cbmFromWeight += ($unitWeight ?? 0) * $quantity / self::KG_PER_CBM;
            }
        }

        $volumetric = $cbm * 1_000_000 / self::VOLUMETRIC_DIVISOR;

        return [
            'weight_kg' => round($weight, 3),
            'volume_cbm' => round($cbm, 6),
            'volumetric_kg' => round($volumetric, 3),
            'billable_kg' => round($weight + $volumetric, 3),
            'billable_cbm' => round($cbm + $cbmFromWeight, 6),
            'cbm_from_weight' => round($cbmFromWeight, 6),
            'missing_weight' => $missingWeight,
            'unmeasured' => $unmeasured,
            'air_max_weight_kg' => self::airMaxWeightKg(),
        ];
    }

    /** L'avion s'arrête au seuil commun ; le bateau reste toujours possible. */
    public function isAvailable(ImportShippingOption $option, array $measure): bool
    {
        return match ($option->mode) {
            'sea' => true,
            'air' => $measure['weight_kg'] <= $measure['air_max_weight_kg'],
            default => false,
        };
    }

    /** Quantité facturée dans l'unité du tarif, ou exception si la mesure manque. */
    public function billedQuantity(ImportShippingOption $option, array $measure): float
    {
        if ($option->rate_type === 'flat') {
            return 1.0;
        }
        // Au kilo (avion, ou ancien bateau gardé jusqu'à la saisie du CBM), le poids réel est indispensable.
        if ($measure['unmeasured'] || ($option->rate_type === 'per_kg' && $measure['missing_weight'])) {
            throw new \Exception(__('orders.parcel_weight_required'));
        }

        return match (true) {
            $option->rate_type === 'per_cbm' => $measure['billable_cbm'],
            // Ancien bateau au kilo, gardé jusqu'à la saisie du tarif au CBM : poids réel seul.
            $option->mode === 'sea' => $measure['weight_kg'],
            default => $measure['billable_kg'],
        };
    }

    /** Détail figé sur la commande : mesures, tarif, coût d'achat et marge ASSO. */
    public function snapshot(ImportShippingOption $option, array $measure, float $quantity, float $price): array
    {
        $cost = $option->cost_amount !== null ? round((float) $option->cost_amount * $quantity, 2) : null;

        return [
            'weight_kg' => $measure['weight_kg'],
            'volume_cbm' => $measure['volume_cbm'],
            'volumetric_kg' => $measure['volumetric_kg'],
            'billed_quantity' => $quantity,
            'rate_type' => $option->rate_type,
            'rate_amount' => (float) $option->rate_amount,
            'cost_amount' => $option->cost_amount !== null ? (float) $option->cost_amount : null,
            'margin_type' => $option->margin_type,
            'margin_value' => $option->margin_value !== null ? (float) $option->margin_value : null,
            'cost_total' => $cost,
            'margin_total' => $cost !== null ? round($price - $cost, 2) : null,
        ];
    }

    /** Au-delà du seuil de l'entrepôt, le client choisit : retrait à Douala ou SOLEX. */
    public function offersWarehousePickup(array $measure): bool
    {
        return $measure['weight_kg'] > ImportHub::pickupThresholdKg();
    }
}
