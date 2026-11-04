<?php

namespace App\Services;

use App\Models\ImportCountry;
use App\Models\ImportShippingOption;
use App\Models\Product;
use App\Models\ProductPriceTier;
use App\Models\ProductVariant;
use App\Support\ImportHub;

/**
 * Transport d'une commande en gros jusqu'à Douala, calculé par le serveur seul :
 * bateau au CBM (1 000 kg comptent pour 1 CBM), avion au plus grand du poids réel
 * et du poids volumétrique. Sans dimensions, seul le poids compte.
 */
class ImportFreightService
{
    private const KG_PER_CBM = 1000;

    public const DEFAULT_AIR_MAX_KG = 100;

    public const DEFAULT_DIVISOR = 6000;

    /**
     * @param  iterable<array{product: Product, tier: ?ProductPriceTier, variant?: ?ProductVariant, quantity: int}>  $lines
     */
    public function measure(iterable $lines, ?string $countryCode): array
    {
        $country = $countryCode ? ImportCountry::where('code', strtoupper($countryCode))->first() : null;
        $divisor = (int) ($country?->air_volumetric_divisor ?: self::DEFAULT_DIVISOR);

        $weight = 0.0;
        $cbm = 0.0;
        $missingWeight = false;
        $unmeasured = false;
        foreach ($lines as $line) {
            $unitWeight = $line['product']->packageWeightKg($line['tier']);
            // La taille choisie par le client prime sur le palier et la fiche.
            $dimensions = ($line['variant'] ?? null)?->packageDimensions() ?? $line['product']->packageDimensions($line['tier']);
            $missingWeight = $missingWeight || ! $unitWeight;
            $unmeasured = $unmeasured || (! $unitWeight && ! $dimensions);
            $weight += ($unitWeight ?? 0) * $line['quantity'];
            if ($dimensions) {
                $cbm += $dimensions[0] * $dimensions[1] * $dimensions[2] / 1_000_000 * $line['quantity'];
            }
        }
        $volumetric = $cbm * 1_000_000 / $divisor;

        return [
            'weight_kg' => round($weight, 3),
            'volume_cbm' => round($cbm, 6),
            'volumetric_kg' => round($volumetric, 3),
            'billable_kg' => round(max($weight, $volumetric), 3),
            'billable_cbm' => round(max($cbm, $weight / self::KG_PER_CBM), 6),
            'missing_weight' => $missingWeight,
            'unmeasured' => $unmeasured,
            'air_max_weight_kg' => (float) ($country?->air_max_weight_kg ?? self::DEFAULT_AIR_MAX_KG),
            'volumetric_divisor' => $divisor,
        ];
    }

    /** L'avion s'arrête au seuil du pays ; le bateau reste toujours possible. */
    public function isAvailable(ImportShippingOption $option, array $measure): bool
    {
        return match ($option->mode) {
            'sea' => true,
            'air' => $measure['billable_kg'] <= $measure['air_max_weight_kg'],
            default => false,
        };
    }

    /** Quantité facturée dans l'unité du tarif, ou exception si la mesure manque. */
    public function billedQuantity(ImportShippingOption $option, array $measure): float
    {
        if ($option->rate_type === 'flat') {
            return 1.0;
        }
        // Ancien bateau au kilo, gardé jusqu'à la saisie du tarif au CBM : poids réel seul.
        $legacySeaByKg = $option->mode === 'sea' && $option->rate_type === 'per_kg';
        if ($measure['unmeasured'] || ($legacySeaByKg && $measure['missing_weight'])) {
            throw new \Exception(__('orders.parcel_weight_required'));
        }

        return match (true) {
            $legacySeaByKg => $measure['weight_kg'],
            $option->rate_type === 'per_cbm' => $measure['billable_cbm'],
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

    /** Au-delà du seuil de l'entrepôt : retrait à Douala, sans SOLEX. */
    public function requiresWarehousePickup(array $measure): bool
    {
        return $measure['billable_kg'] > ImportHub::pickupThresholdKg();
    }
}
