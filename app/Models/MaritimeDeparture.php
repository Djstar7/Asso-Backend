<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Départ maritime d'un partenaire, entre deux QG.
 *
 * Réservable jusqu'à J − `maritime_booking_cutoff_days` (défaut 7) ; le colis
 * doit être déposé au QG de départ avant J − `maritime_dropoff_days_before`
 * (défaut 4), faute de quoi l'expéditeur est remboursé hors frais ASSO.
 */
class MaritimeDeparture extends Model
{
    public const STATUS_OPEN = 'open';
    public const STATUS_CLOSED = 'closed';
    public const STATUS_DEPARTED = 'departed';
    public const STATUS_ARRIVED = 'arrived';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_OPEN => 'Ouvert aux réservations',
        self::STATUS_CLOSED => 'Réservations closes',
        self::STATUS_DEPARTED => 'Parti',
        self::STATUS_ARRIVED => 'Arrivé',
        self::STATUS_CANCELLED => 'Annulé',
    ];

    protected $fillable = [
        'deliverer_company_id',
        'origin_hub_id',
        'destination_hub_id',
        'departure_date',
        'estimated_arrival_date',
        'capacity_kg',
        'remaining_kg',
        'price_per_kg',
        'price_per_cbm',
        'kg_per_cbm',
        'currency',
        'status',
        'notes',
    ];

    protected $casts = [
        'departure_date' => 'date',
        'estimated_arrival_date' => 'date',
        'capacity_kg' => 'decimal:2',
        'remaining_kg' => 'decimal:2',
        'price_per_kg' => 'decimal:2',
        'price_per_cbm' => 'decimal:2',
        'kg_per_cbm' => 'decimal:2',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(DelivererCompany::class, 'deliverer_company_id');
    }

    public function originHub(): BelongsTo
    {
        return $this->belongsTo(MaritimeHub::class, 'origin_hub_id');
    }

    public function destinationHub(): BelongsTo
    {
        return $this->belongsTo(MaritimeHub::class, 'destination_hub_id');
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(MaritimeBooking::class);
    }

    // ------------------------------------------------------------------
    // Réglages (admin → Maritime)
    // ------------------------------------------------------------------

    public static function commissionRate(): float
    {
        return max(0.0, (float) Setting::get('maritime_commission_rate', DiaspoOffer::commissionRate()));
    }

    public static function bookingCutoffDays(): int
    {
        return max(0, (int) Setting::get('maritime_booking_cutoff_days', 7));
    }

    /** Kilos comptés pour 1 m³ quand un départ est tarifé au m³ (réglable). */
    public static function defaultKgPerCbm(): float
    {
        return max(1.0, (float) Setting::get('maritime_kg_per_cbm', 1000));
    }

    public static function dropoffDaysBefore(): int
    {
        return max(0, (int) Setting::get('maritime_dropoff_days_before', 4));
    }

    // ------------------------------------------------------------------
    // Calendrier
    // ------------------------------------------------------------------

    /** Dernier jour où l'on peut réserver. */
    public function bookingClosesOn(): Carbon
    {
        return $this->departure_date->copy()->subDays(self::bookingCutoffDays());
    }

    /** Date limite de dépôt du colis au QG de départ. */
    public function dropoffDeadline(): Carbon
    {
        return $this->departure_date->copy()->subDays(self::dropoffDaysBefore());
    }

    /** Jours restants pour réserver (0 = dernier jour, négatif = clos). */
    public function daysLeftToBook(): int
    {
        return (int) today()->diffInDays($this->bookingClosesOn(), false);
    }

    public function isBookable(): bool
    {
        return $this->status === self::STATUS_OPEN
            && $this->daysLeftToBook() >= 0
            && (float) $this->remaining_kg > 0;
    }

    public function isPricedPerCbm(): bool
    {
        return (float) $this->price_per_cbm > 0 && (float) $this->kg_per_cbm > 0;
    }

    /** Volume (m³) correspondant à des kilos, selon la conversion du départ. */
    public function volumeForKg(float $kg): ?float
    {
        return $this->isPricedPerCbm() ? round($kg / (float) $this->kg_per_cbm, 4) : null;
    }

    /**
     * Fret du partenaire pour des kilos, sans commission. Départ au m³ : les
     * kilos sont convertis en m³ puis multipliés par le tarif au m³.
     */
    public function partnerFreightFor(float $kg): float
    {
        return $this->isPricedPerCbm()
            ? round($kg / (float) $this->kg_per_cbm * (float) $this->price_per_cbm, 2)
            : round($kg * (float) $this->price_per_kg, 2);
    }

    /** Fret payé par l'expéditeur : fret du partenaire majoré de la commission ASSO. */
    public function publicFreightFor(float $kg): float
    {
        return round($this->partnerFreightFor($kg) * (1 + self::commissionRate() / 100), 2);
    }

    /** Prix au kilo affiché : prix du partenaire majoré de la commission ASSO. */
    public function publicPricePerKg(): float
    {
        $partnerPerKg = $this->isPricedPerCbm()
            ? (float) $this->price_per_cbm / (float) $this->kg_per_cbm
            : (float) $this->price_per_kg;

        return round($partnerPerKg * (1 + self::commissionRate() / 100), 2);
    }

    public function scopeUpcoming($query)
    {
        return $query->whereIn('status', [self::STATUS_OPEN, self::STATUS_CLOSED])
            ->whereDate('departure_date', '>=', today());
    }

    public function toApi(): array
    {
        return [
            'id' => $this->id,
            'transport_mode' => 'sea',
            'partner' => $this->relationLoaded('company') && $this->company ? [
                'id' => $this->company->id,
                'name' => $this->company->name,
                'logo' => $this->company->logo ? media_url($this->company->logo) : null,
                'phone' => $this->company->phone,
            ] : null,
            'origin_hub' => $this->relationLoaded('originHub') ? $this->originHub?->toApi() : null,
            'destination_hub' => $this->relationLoaded('destinationHub') ? $this->destinationHub?->toApi() : null,
            'departure_date' => $this->departure_date->toDateString(),
            'estimated_arrival_date' => $this->estimated_arrival_date?->toDateString(),
            'booking_closes_on' => $this->bookingClosesOn()->toDateString(),
            'dropoff_deadline' => $this->dropoffDeadline()->toDateString(),
            'days_left_to_book' => $this->daysLeftToBook(),
            'is_bookable' => $this->isBookable(),
            'capacity_kg' => (float) $this->capacity_kg,
            'remaining_kg' => (float) $this->remaining_kg,
            'price_per_kg' => $this->publicPricePerKg(),
            // Départ tarifé au m³ : tarif public au m³ et conversion, pour l'affichage.
            'price_per_cbm' => $this->isPricedPerCbm()
                ? round((float) $this->price_per_cbm * (1 + self::commissionRate() / 100), 2)
                : null,
            'kg_per_cbm' => $this->isPricedPerCbm() ? (float) $this->kg_per_cbm : null,
            'currency' => $this->currency,
            'status' => $this->status,
            'notes' => $this->notes,
        ];
    }
}
