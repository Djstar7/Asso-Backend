<?php

namespace App\Models;

use App\Support\CountryCode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** QG d'un partenaire maritime : lieu fixe de dépôt (départ) ou de retrait (arrivée). */
class MaritimeHub extends Model
{
    protected $fillable = [
        'deliverer_company_id',
        'name',
        'country',
        'city',
        'address',
        'phone',
        'opening_hours',
        'latitude',
        'longitude',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'latitude' => 'float',
        'longitude' => 'float',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(DelivererCompany::class, 'deliverer_company_id');
    }

    public function toApi(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'country' => $this->country,
            'country_name' => CountryCode::NAMES[$this->country] ?? $this->country,
            'city' => $this->city,
            'address' => $this->address,
            'phone' => $this->phone,
            'opening_hours' => $this->opening_hours,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
        ];
    }
}
