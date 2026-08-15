<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class DeliveryZone extends Model
{
    protected $fillable = [
        'deliverer_company_id',
        'name',
        'city',
        'zone_data',
        'center_latitude',
        'center_longitude',
        'is_active',
    ];

    protected $casts = [
        'zone_data' => 'array',
        'center_latitude' => 'decimal:8',
        'center_longitude' => 'decimal:8',
        'is_active' => 'boolean',
    ];

    /**
     * Get the deliverer company that owns this zone
     */
    public function delivererCompany(): BelongsTo
    {
        return $this->belongsTo(DelivererCompany::class);
    }

    /**
     * Get the pricelist for this zone
     */
    public function pricelist(): HasOne
    {
        return $this->hasOne(DeliveryPricelist::class);
    }

    /**
     * Get active pricelist
     */
    public function activePricelist(): HasOne
    {
        return $this->hasOne(DeliveryPricelist::class)->where('is_active', true);
    }
}
