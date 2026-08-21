<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Option d'expédition internationale (Avion / Bateau / Express) pour le module gros.
 * `country_code` nulle = option valable pour tous les pays d'import.
 */
class ImportShippingOption extends Model
{
    protected $fillable = [
        'country_code',
        'mode',
        'rate_type',
        'rate_amount',
        'currency',
        'lead_time_days',
        'expedition_note',
        'destinations',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'rate_amount' => 'decimal:2',
        'lead_time_days' => 'integer',
        'destinations' => 'array',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public const MODE_LABELS = [
        'air' => 'Avion',
        'sea' => 'Bateau',
        'express' => 'Express',
    ];

    public function scopeActiveForCountry($query, ?string $code)
    {
        return $query->where('is_active', true)
            ->where(function ($q) use ($code) {
                $q->whereNull('country_code');
                if ($code) {
                    $q->orWhere('country_code', strtoupper($code));
                }
            })
            ->orderBy('sort_order');
    }

    /**
     * Calcule le coût d'expédition pour un poids (kg) et/ou un volume (CBM) donnés.
     */
    public function computeCost(float $weightKg = 0, float $cbm = 0): float
    {
        return match ($this->rate_type) {
            'per_kg' => round((float) $this->rate_amount * $weightKg, 2),
            'per_cbm' => round((float) $this->rate_amount * $cbm, 2),
            default => (float) $this->rate_amount, // flat
        };
    }

    public function toApi(): array
    {
        return [
            'id' => $this->id,
            'country_code' => $this->country_code,
            'mode' => $this->mode,
            'mode_label' => self::MODE_LABELS[$this->mode] ?? $this->mode,
            'rate_type' => $this->rate_type,
            'rate_amount' => (float) $this->rate_amount,
            'currency' => $this->currency,
            'lead_time_days' => $this->lead_time_days,
            'expedition_note' => $this->expedition_note,
            'destinations' => $this->destinations ?? [],
            'formatted_rate' => number_format((float) $this->rate_amount, 0, ',', ' ') . ' ' . $this->currency
                . match ($this->rate_type) { 'per_kg' => ' / kg', 'per_cbm' => ' / CBM', default => '' },
        ];
    }
}
