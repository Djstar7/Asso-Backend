<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Tarif commercial sans date de départ : ne crée aucune réservation fictive. */
class PartnerFreightRate extends Model
{
    protected $fillable = [
        'deliverer_company_id', 'origin_country', 'destination_country',
        'transport_mode', 'cargo_category', 'rate_unit', 'rate_amount', 'public_rate_amount', 'frequency_days', 'currency',
        'transit_days_min', 'transit_days_max', 'freight_payment_timing', 'notes', 'is_active',
    ];

    protected $casts = [
        'rate_amount' => 'decimal:2', 'public_rate_amount' => 'decimal:2', 'frequency_days' => 'integer', 'transit_days_min' => 'integer',
        'transit_days_max' => 'integer', 'is_active' => 'boolean',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(DelivererCompany::class, 'deliverer_company_id');
    }

    public function snapshot(): array
    {
        return $this->only([
            'origin_country', 'destination_country', 'transport_mode', 'cargo_category',
            'rate_unit', 'rate_amount', 'public_rate_amount', 'frequency_days', 'currency',
            'transit_days_min', 'transit_days_max', 'freight_payment_timing', 'notes',
        ]);
    }
}
