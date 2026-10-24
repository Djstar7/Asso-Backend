<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeliveryAgency extends Model
{
    protected $fillable = ['deliverer_company_id', 'code', 'name', 'city', 'address', 'phone', 'is_active',
        'country', 'latitude', 'longitude', 'is_international_reference', 'is_provisional', 'source_url', 'source_dataset', 'verified_at', 'coordinate_precision', 'collection_pricing'];

    protected $casts = ['is_active' => 'boolean', 'is_international_reference' => 'boolean', 'is_provisional' => 'boolean',
        'collection_pricing' => 'array', 'latitude' => 'float', 'longitude' => 'float', 'verified_at' => 'datetime'];

    public function company(): BelongsTo
    {
        return $this->belongsTo(DelivererCompany::class, 'deliverer_company_id');
    }

    public function toPickupArray(): array
    {
        return $this->only(['id', 'code', 'name', 'city', 'address', 'phone', 'latitude', 'longitude', 'coordinate_precision', 'is_provisional']);
    }
}
