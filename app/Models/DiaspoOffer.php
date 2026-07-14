<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DiaspoOffer extends Model
{
    protected $fillable = [
        'user_id', 'status', 'verification_status', 'verified_at', 'verified_by', 'rejection_reason',
        'departure_country', 'departure_city', 'departure_datetime',
        'arrival_country', 'arrival_city', 'arrival_datetime',
        'price_per_kg', 'available_kg', 'remaining_kg', 'currency',
        'views_count', 'bookings_count',
    ];

    protected $casts = [
        'departure_datetime' => 'datetime',
        'arrival_datetime' => 'datetime',
        'verified_at' => 'datetime',
        'price_per_kg' => 'decimal:2',
        'available_kg' => 'decimal:2',
        'remaining_kg' => 'decimal:2',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(DiaspoBooking::class);
    }

    public function getIsAvailableAttribute(): bool
    {
        return $this->status === 'active' && (float) $this->remaining_kg > 0;
    }

    public function getTripDurationHoursAttribute(): ?float
    {
        if (!$this->departure_datetime || !$this->arrival_datetime) {
            return null;
        }
        return round($this->departure_datetime->diffInMinutes($this->arrival_datetime) / 60, 1);
    }

    /** Forme JSON attendue par le mobile (DiaspoOffer.fromJson). */
    public function toApi(): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'status' => $this->status,
            'verification_status' => $this->verification_status,
            'verified_at' => $this->verified_at?->toIso8601String(),
            'verified_by' => $this->verified_by,
            'rejection_reason' => $this->rejection_reason,
            'departure_country' => $this->departure_country,
            'departure_city' => $this->departure_city,
            'departure_datetime' => $this->departure_datetime?->toIso8601String(),
            'arrival_country' => $this->arrival_country,
            'arrival_city' => $this->arrival_city,
            'arrival_datetime' => $this->arrival_datetime?->toIso8601String(),
            'price_per_kg' => (float) $this->price_per_kg,
            'available_kg' => (float) $this->available_kg,
            'remaining_kg' => (float) $this->remaining_kg,
            'currency' => $this->currency,
            'views_count' => $this->views_count,
            'bookings_count' => $this->bookings_count,
            'formatted_price' => number_format((float) $this->price_per_kg, 0) . ' ' . $this->currency . '/kg',
            'is_available' => $this->is_available,
            'trip_duration_hours' => $this->trip_duration_hours,
            'user' => $this->relationLoaded('user') && $this->user ? [
                'id' => $this->user->id,
                'first_name' => $this->user->first_name,
                'last_name' => $this->user->last_name,
                'avatar' => $this->user->avatar,
                'phone' => $this->user->phone,
            ] : null,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
