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
        'partner_freight_rate_id',
        'freight_rate_snapshot',
        'is_demo',
    ];

    protected $casts = [
        'departure_date' => 'date',
        'estimated_arrival_date' => 'date',
        'capacity_kg' => 'decimal:2',
        'remaining_kg' => 'decimal:2',
        'price_per_kg' => 'decimal:2',
        'price_per_cbm' => 'decimal:2',
        'kg_per_cbm' => 'decimal:2',
        'freight_rate_snapshot' => 'array',
        'is_demo' => 'boolean',
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

    /** Départ tarifé au m³ : 1 m³ = 1 000 kg (règle fixée par ASSO). */
    public const KG_PER_CBM = 1000;

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
        if (app()->isProduction() && $this->is_demo) {
            return false;
        }
        foreach (['company', 'originHub', 'destinationHub'] as $relation) {
            if ($this->relationLoaded($relation)) {
                $model = $this->getRelation($relation);
                if (! $model || ! $model->is_active || (app()->isProduction() && $relation !== 'company' && $model->is_demo)) {
                    return false;
                }
            }
        }

        return $this->status === self::STATUS_OPEN
            && $this->daysLeftToBook() >= 0
            && (float) $this->remaining_kg > 0;
    }

    public function isPricedPerCbm(): bool
    {
        return (float) $this->price_per_cbm > 0 && (float) $this->kg_per_cbm > 0;
    }

    public function requiresQuote(): bool
    {
        return $this->originHub?->country === 'CN'
            && ! in_array($this->destinationHub?->country, ['CN', 'CM', null], true)
            && ($this->freight_rate_snapshot['transport_mode'] ?? 'sea') === 'sea';
    }

    public function quoteIdentity(): array
    {
        return [
            'company_id' => $this->deliverer_company_id,
            'origin_hub_id' => $this->origin_hub_id,
            'destination_hub_id' => $this->destination_hub_id,
            'departure_date' => $this->departure_date->toDateString(),
            'origin_country' => $this->originHub?->country,
            'destination_country' => $this->destinationHub?->country,
            'transport_mode' => $this->freight_rate_snapshot['transport_mode'] ?? 'sea',
            'cargo_category' => $this->freight_rate_snapshot['cargo_category'] ?? 'general',
        ];
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
        if (isset($this->freight_rate_snapshot['public_rate_amount'])) {
            $units = $this->isPricedPerCbm() ? $kg / (float) $this->kg_per_cbm : $kg;

            return round($units * (float) $this->freight_rate_snapshot['public_rate_amount'], 2);
        }

        return round($this->partnerFreightFor($kg) * (1 + self::commissionRate() / 100), 2);
    }

    /** Prix au kilo affiché : prix du partenaire majoré de la commission ASSO. */
    public function publicPricePerKg(): float
    {
        if (isset($this->freight_rate_snapshot['public_rate_amount'])) {
            return round((float) $this->freight_rate_snapshot['public_rate_amount'] / ($this->isPricedPerCbm() ? (float) $this->kg_per_cbm : 1), 2);
        }
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

    public function toApi(?int $viewerId = null): array
    {
        $includeMeeting = $viewerId !== null && $this->bookings()
            ->where('sender_user_id', $viewerId)->where('payment_status', 'completed')
            ->where('status', '!=', MaritimeBooking::STATUS_CANCELLED)->exists();

        return [
            'id' => $this->id,
            'requires_quote' => $this->requiresQuote(),
            'transport_mode' => $this->freight_rate_snapshot['transport_mode'] ?? 'sea',
            'cargo_category' => $this->freight_rate_snapshot['cargo_category'] ?? 'general',
            'transit_days_min' => $this->freight_rate_snapshot['transit_days_min'] ?? null,
            'transit_days_max' => $this->freight_rate_snapshot['transit_days_max'] ?? null,
            'frequency_days' => $this->freight_rate_snapshot['frequency_days'] ?? null,
            'partner_freight_payment_timing' => $this->freight_rate_snapshot['freight_payment_timing'] ?? 'departure',
            'partner' => null,
            'meeting_details_available' => $includeMeeting,
            'origin_hub' => $this->relationLoaded('originHub') ? $this->originHub?->toApi($includeMeeting) : null,
            'destination_hub' => $this->relationLoaded('destinationHub') ? $this->destinationHub?->toApi($includeMeeting) : null,
            'departure_date' => $this->departure_date->toDateString(),
            'estimated_arrival_date' => $this->estimated_arrival_date?->toDateString(),
            'booking_closes_on' => $this->bookingClosesOn()->toDateString(),
            'dropoff_deadline' => $this->dropoffDeadline()->toDateString(),
            'days_left_to_book' => $this->daysLeftToBook(),
            'is_bookable' => $this->isBookable(),
            'capacity_kg' => (float) $this->capacity_kg,
            'remaining_kg' => (float) $this->remaining_kg,
            'price_per_kg' => $this->requiresQuote() ? null : $this->publicPricePerKg(),
            // Départ tarifé au m³ : tarif public au m³ et conversion, pour l'affichage.
            'price_per_cbm' => ! $this->requiresQuote() && $this->isPricedPerCbm()
                ? (float) ($this->freight_rate_snapshot['public_rate_amount'] ?? round((float) $this->price_per_cbm * (1 + self::commissionRate() / 100), 2))
                : null,
            'kg_per_cbm' => $this->isPricedPerCbm() ? (float) $this->kg_per_cbm : null,
            'currency' => $this->currency,
            'status' => $this->status,
            'notes' => $includeMeeting ? $this->notes : null,
        ];
    }
}
