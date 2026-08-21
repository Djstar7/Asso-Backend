<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class DiaspoOffer extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'status',
        'verification_status',
        'verified_at',
        'verified_by',
        'rejection_reason',
        'departure_country',
        'departure_city',
        'departure_datetime',
        'arrival_country',
        'arrival_city',
        'arrival_datetime',
        'price_per_kg',
        'available_kg',
        'remaining_kg',
        'currency',
        'views_count',
        'bookings_count',
    ];

    protected $casts = [
        'departure_datetime' => 'datetime',
        'arrival_datetime' => 'datetime',
        'verified_at' => 'datetime',
        'price_per_kg' => 'decimal:2',
        'available_kg' => 'decimal:2',
        'remaining_kg' => 'decimal:2',
        'views_count' => 'integer',
        'bookings_count' => 'integer',
    ];

    protected $appends = [
        'formatted_price',
        'is_available',
        'trip_duration_hours',
    ];

    /**
     * Boot the model
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($offer) {
            // Initialiser remaining_kg à available_kg lors de la création
            if ($offer->remaining_kg === null) {
                $offer->remaining_kg = $offer->available_kg;
            }
        });
    }

    /**
     * Relations
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(DiaspoBooking::class);
    }

    /**
     * Scopes
     */
    public function scopeApproved($query)
    {
        return $query->where('status', 'approved')
            ->where('verification_status', 'verified');
    }

    public function scopeAvailable($query)
    {
        return $query->approved()
            ->where('remaining_kg', '>', 0)
            ->where('departure_datetime', '>', now());
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending')
            ->orWhere('verification_status', 'pending');
    }

    public function scopeRecent($query)
    {
        return $query->orderBy('created_at', 'desc');
    }

    /**
     * Filter by route
     */
    public function scopeByRoute($query, $departureCountry = null, $arrivalCountry = null)
    {
        if ($departureCountry) {
            $query->where('departure_country', $departureCountry);
        }
        if ($arrivalCountry) {
            $query->where('arrival_country', $arrivalCountry);
        }
        return $query;
    }

    /**
     * Accessors
     */
    public function getFormattedPriceAttribute(): string
    {
        return number_format($this->price_per_kg, 2) . ' ' . $this->currency;
    }

    public function getIsAvailableAttribute(): bool
    {
        return $this->status === 'approved'
            && $this->verification_status === 'verified'
            && $this->remaining_kg > 0
            && $this->departure_datetime > now();
    }

    public function getTripDurationHoursAttribute(): ?float
    {
        if (!$this->departure_datetime || !$this->arrival_datetime) {
            return null;
        }
        return $this->departure_datetime->diffInHours($this->arrival_datetime);
    }

    /**
     * Helpers
     */
    public function incrementViews(): void
    {
        $this->increment('views_count');
    }

    public function canBeBookedBy(?int $userId = null): bool
    {
        if (!$userId) {
            $userId = auth()->id();
        }

        // Ne peut pas réserver sa propre offre
        if ($this->user_id === $userId) {
            return false;
        }

        return $this->is_available;
    }

    /**
     * Check if offer has expired
     */
    public function markAsExpiredIfNeeded(): void
    {
        if ($this->departure_datetime < now() && $this->status !== 'expired') {
            $this->update(['status' => 'expired']);
        }
    }

    /**
     * Sérialisation API (contrat mobile) — utilisée par le flux réservations
     * (DiaspoController, paiement KPay direct).
     */
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
            // Suppression interdite dès qu'une réservation est payée/confirmée
            // (même règle que DiaspoController::destroyOffer) → masque le bouton côté app.
            'can_delete' => !$this->bookings()->whereIn('status', ['paid', 'confirmed'])->exists(),
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
