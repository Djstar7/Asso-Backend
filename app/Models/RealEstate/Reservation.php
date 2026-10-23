<?php

namespace App\Models\RealEstate;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Réservation d'une maison (avance de loyer) ou acompte sur un terrain.
 * Une réservation payée débloque les coordonnées du bailleur ou du cabinet.
 */
class Reservation extends Model
{
    protected $table = 'real_estate_reservations';

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
        'reference', 'listing_id', 'unit_id', 'tenant_id', 'payee_id', 'partner_cabinet_id', 'partner_payee_id',
        'partner_commission_amount', 'partner_cabinet_name', 'net_payee_kind', 'kind', 'move_in_date',
        'months', 'occupants', 'applicant_status', 'school_or_employer', 'message',
        'displayed_unit_price', 'base_amount', 'commission_amount', 'deposit_percent', 'advance_months', 'owner_amount', 'platform_amount', 'total_amount',
        'payment_method', 'payment_reference', 'status', 'contract_accepted_at', 'paid_at',
        'visit_deadline_at', 'confirmed_at', 'released_at', 'contract_path', 'invoice_path',
    ];

    protected $casts = [
        'occupants' => 'array',
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
