<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FreightInquiry extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['kg' => 'float', 'volume_cbm' => 'float', 'freight_amount' => 'float', 'partner_amount' => 'float', 'expires_at' => 'datetime', 'accepted_at' => 'datetime', 'departure_snapshot' => 'array'];

    public function departure()
    {
        return $this->belongsTo(MaritimeDeparture::class, 'maritime_departure_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function toApi(): array
    {
        return $this->only(['id', 'destination_country', 'kg', 'volume_cbm', 'cargo_category', 'description', 'status', 'conversation_id', 'freight_amount', 'currency', 'expires_at', 'maritime_booking_id']) + [
            'departure' => $this->departure?->loadMissing(['originHub', 'destinationHub', 'company'])->toApi($this->user_id),
        ];
    }
}
