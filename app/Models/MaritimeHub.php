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
        'is_demo',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_demo' => 'boolean',
        'latitude' => 'float',
        'longitude' => 'float',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(DelivererCompany::class, 'deliverer_company_id');
    }

    public function toApi(bool $includeMeeting = false): array
    {
        return [
            'id' => $this->id,
            'name' => $includeMeeting ? $this->name : null,
            'country' => $this->country,
            'country_name' => CountryCode::NAMES[$this->country] ?? $this->country,
            'city' => $this->city,
            'address' => $includeMeeting ? $this->address : null,
            'phone' => $includeMeeting ? $this->phone : null,
            'opening_hours' => $includeMeeting ? $this->opening_hours : null,
            'latitude' => $includeMeeting ? $this->latitude : null,
            'longitude' => $includeMeeting ? $this->longitude : null,
        ];
    }
}
