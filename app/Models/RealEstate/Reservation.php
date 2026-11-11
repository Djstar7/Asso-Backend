<?php

namespace App\Models\RealEstate;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Réservation d'une maison (avance de loyer) ou acompte sur un terrain.
 * Une réservation payée débloque les coordonnées du bailleur ou du cabinet.
 */
class Reservation extends Model
{
    protected $table = 'real_estate_reservations';

    protected static function booted(): void
    {
        // Commission du chef de la rubrique Immobilier : paiement, déblocage, remboursement.
        static::saved(function (Reservation $reservation) {
            if ($reservation->paid_at && $reservation->wasChanged(['status', 'paid_at', 'released_at'])) {
                \App\Services\DivisionCommissionService::syncReservationAfterCommit($reservation->id);
            }
        });
    }

    /** Statuts dans lesquels le paiement a été encaissé. */
    public const PAID_STATUSES = ['paid', 'confirmed', 'disputed', 'released'];

    /** Les justificatifs restent accessibles après un remboursement. */
    public function canAccessDocuments(): bool
    {
        return in_array($this->status, [...self::PAID_STATUSES, 'refunded'], true);
    }

    /** Distingue les frais ajoutés des anciennes réservations à frais inclus. */
    public function hasAdditionalPlatformFee(): bool
    {
        return (int) $this->owner_amount === (int) $this->base_amount
            && (int) $this->total_amount === (int) $this->base_amount + (int) $this->platform_amount + (int) $this->commission_amount;
    }

    protected $fillable = [
        'reference', 'listing_id', 'unit_id', 'quantity', 'whole', 'tenant_id', 'payee_id', 'partner_cabinet_id', 'partner_payee_id',
        'partner_commission_amount', 'partner_cabinet_name', 'net_payee_kind', 'kind', 'move_in_date',
        'months', 'occupants', 'applicant_status', 'school_or_employer', 'message',
        'displayed_unit_price', 'base_amount', 'commission_amount', 'deposit_percent', 'advance_months', 'owner_amount', 'platform_amount', 'total_amount',
        'payment_method', 'payment_reference', 'status', 'contract_accepted_at', 'paid_at',
        'visit_deadline_at', 'confirmed_at', 'released_at', 'contract_path', 'invoice_path',
    ];

    protected $casts = [
        'occupants' => 'array',
        'quantity' => 'integer',
        'whole' => 'boolean',
        'move_in_date' => 'date',
        'contract_accepted_at' => 'datetime',
        'paid_at' => 'datetime',
        'visit_deadline_at' => 'datetime',
        'confirmed_at' => 'datetime',
        'released_at' => 'datetime',
    ];

    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class)->withTrashed();
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /** Morceaux achetés (terrain) : lot × quantité. */
    public function lots(): HasMany
    {
        return $this->hasMany(ReservationLot::class);
    }

    /**
     * Places retirées au paiement (et rendues en cas de remboursement),
     * unit_id => quantité. Les anciennes réservations n'ont que unit_id.
     *
     * @return array<int, int>
     */
    public function lotQuantities(): array
    {
        $lots = $this->relationLoaded('lots') ? $this->lots : $this->lots()->get();
        if ($lots->isNotEmpty()) {
            return $lots->whereNotNull('unit_id')
                ->groupBy('unit_id')
                ->map(fn ($rows) => (int) $rows->sum('quantity'))
                ->all();
        }

        return $this->unit_id ? [(int) $this->unit_id => 1] : [];
    }

    /** « Parcelle », « 3 × Parcelle 1 000 m² » ou « Tout le terrain (3 morceaux) ». */
    public function lotsLabel(): ?string
    {
        $lots = $this->relationLoaded('lots') ? $this->lots : $this->lots()->get();
        $count = (int) $lots->sum('quantity');
        if ($this->whole && $count > 1) {
            return "Tout le terrain ({$count} morceaux)";
        }
        if ($lots->count() > 1) {
            return $lots->map(fn (ReservationLot $l) => ($l->quantity > 1 ? $l->quantity.' × ' : '').$l->label)->implode(' + ');
        }
        $lot = $lots->first();
        if ($lot && $lot->quantity > 1) {
            return $lot->quantity.' × '.$lot->label;
        }

        return $lot?->label ?? $this->unit?->label;
    }

    /**
     * Lots liés à une réservation d'une annonce (unit_id => nombre de réservations) :
     * ils ne sont jamais supprimés (contrat, facture, litiges).
     *
     * @return array<int, int>
     */
    public static function reservedUnitCounts(int $listingId): array
    {
        $counts = self::where('listing_id', $listingId)->whereNotNull('unit_id')
            ->selectRaw('unit_id, count(*) as n')->groupBy('unit_id')->pluck('n', 'unit_id')->all();
        $lots = ReservationLot::whereNotNull('unit_id')
            ->whereHas('reservation', fn ($q) => $q->where('listing_id', $listingId))
            ->selectRaw('unit_id, count(*) as n')->groupBy('unit_id')->pluck('n', 'unit_id')->all();
        foreach ($lots as $unitId => $n) {
            $counts[$unitId] = max((int) ($counts[$unitId] ?? 0), (int) $n);
        }

        return $counts;
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'tenant_id');
    }

    public function payee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'payee_id');
    }

    public function partnerCabinet(): BelongsTo
    {
        return $this->belongsTo(Cabinet::class, 'partner_cabinet_id')->withTrashed();
    }

    public function partnerPayee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'partner_payee_id');
    }

    public function dispute(): HasOne
    {
        return $this->hasOne(Dispute::class);
    }
}
