<?php

namespace App\Support;

/**
 * Grille tarifaire au poids réel (kg), commune aux livreurs locaux et aux transporteurs.
 *
 * pricing_data = {
 *   "ranges": [{"min": 0, "max": 2, "price": 2500, "label": "Plis & paquets"},
 *              {"min": 2, "max": 10, "price": 4000, "label": "Colis"}],
 *   "extra_per_kg": 150   // au-delà de la dernière tranche, par kg entamé
 * }
 */
class WeightGrid
{
    /**
     * Prix pour un poids donné, ou null si le colis dépasse la grille (pas de kg supplémentaire).
     *
     * @return array{price: float, range_label: string, range_price: float, extra_kg: int, extra_per_kg: float, extra_price: float}|null
     */
    public static function price(array $pricingData, float $weightKg): ?array
    {
        $ranges = collect($pricingData['ranges'] ?? [])
            ->filter(fn ($r) => isset($r['max'], $r['price']))
            ->map(fn ($r) => [
                'min' => (float) ($r['min'] ?? 0),
                'max' => (float) $r['max'],
                'price' => (float) $r['price'],
                'label' => trim((string) ($r['label'] ?? '')),
                'label_en' => trim((string) ($r['label_en'] ?? '')),
            ])
            ->sortBy('max')
            ->values();

        if ($ranges->isEmpty()) {
            return null;
        }

        $extraPerKg = (float) ($pricingData['extra_per_kg'] ?? 0);

        foreach ($ranges as $range) {
            if ($weightKg <= $range['max']) {
                return [
                    'price' => $range['price'],
                    'range_label' => self::label($range),
                    'range_price' => $range['price'],
                    'extra_kg' => 0,
                    'extra_per_kg' => $extraPerKg,
                    'extra_price' => 0.0,
                ];
            }
        }

        // Au-delà de la dernière tranche : prix de la tranche + chaque kg entamé.
        if ($extraPerKg <= 0) {
            return null;
        }

        $last = $ranges->last();
        $extraKg = (int) ceil(round($weightKg - $last['max'], 3));
        $extraPrice = $extraKg * $extraPerKg;

        return [
            'price' => $last['price'] + $extraPrice,
            'range_label' => self::label($last),
            'range_price' => $last['price'],
            'extra_kg' => $extraKg,
            'extra_per_kg' => $extraPerKg,
            'extra_price' => $extraPrice,
        ];
    }

    /** Grille lisible pour l'acheteur : [« Plis & paquets (0 à 2 kg) » => 2500, …]. */
    public static function describe(array $pricingData): array
    {
        return collect($pricingData['ranges'] ?? [])
            ->sortBy(fn ($r) => (float) ($r['max'] ?? 0))
            ->map(fn ($r) => [
                'label' => self::label([
                    'min' => (float) ($r['min'] ?? 0),
                    'max' => (float) ($r['max'] ?? 0),
                    'label' => trim((string) ($r['label'] ?? '')),
                    'label_en' => trim((string) ($r['label_en'] ?? '')),
                ]),
                'price' => (float) ($r['price'] ?? 0),
            ])
            ->values()
            ->all();
    }

    private static function label(array $range): string
    {
        $bounds = __('delivery.quote.kg_range', ['min' => self::kg($range['min']), 'max' => self::kg($range['max'])]);
        // Libellé saisi en français, avec sa version anglaise facultative (label_en).
        $label = app()->getLocale() === 'en' && ($range['label_en'] ?? '') !== '' ? $range['label_en'] : $range['label'];

        return $label !== '' ? "{$label} ({$bounds})" : $bounds;
    }

    private static function kg(float $value): string
    {
        return rtrim(rtrim(number_format($value, 3, ',', ''), '0'), ',');
    }
}
