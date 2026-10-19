<?php

namespace App\Models\RealEstate;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Type de logement d'une annonce (studio, chambre, appartement…) ou lot de
 * surface d'un terrain. Une annonce simple n'en a qu'un.
 */
class Unit extends Model
{
    protected $table = 'real_estate_units';

    protected $fillable = [
        'listing_id', 'label', 'surface_min', 'surface_max', 'net_price', 'old_net_price',
        'capacity', 'kitchen', 'furnished', 'serviced', 'units_total', 'units_available',
        'available_from', 'position',
    ];

    protected $casts = [
        'furnished' => 'boolean',
        'serviced' => 'boolean',
        'available_from' => 'date',
        'net_price' => 'integer',
        'old_net_price' => 'integer',
    ];

    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }
}
