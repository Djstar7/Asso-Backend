<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Lang;

/**
 * Envoi maritime : kilos réservés sur un départ, payés par l'expéditeur.
 *
 * Cycle : pending → paid → dropped_off (déposé au QG) → shipped (embarqué)
 * → arrived (au QG d'arrivée) → completed (code remis par le destinataire).
 * Annulation : échec de paiement, annulation avant dépôt, ou date limite de
 * dépôt dépassée (remboursement hors commission ASSO).
 */
class MaritimeBooking extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PAID = 'paid';

    public const STATUS_DROPPED_OFF = 'dropped_off';

    public const STATUS_SHIPPED = 'shipped';

    public const STATUS_ARRIVED = 'arrived';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_PENDING => 'Paiement en attente',
        self::STATUS_PAID => 'Payé — colis attendu au QG',
        self::STATUS_DROPPED_OFF => 'Colis déposé au QG',
        self::STATUS_SHIPPED => 'Embarqué',
        self::STATUS_ARRIVED => 'Arrivé au QG de destination',
        self::STATUS_COMPLETED => 'Remis au destinataire',
        self::STATUS_CANCELLED => 'Annulé',
    ];

    /** Étapes que l'admin enregistre, dans l'ordre : statut attendu → statut suivant. */
    public const NEXT_STEP = [
        self::STATUS_PAID => self::STATUS_DROPPED_OFF,
        self::STATUS_DROPPED_OFF => self::STATUS_SHIPPED,
        self::STATUS_SHIPPED => self::STATUS_ARRIVED,
    ];

    protected $fillable = [
        'maritime_departure_id',
        'sender_user_id',
        'kg_booked',
        'volume_cbm',
        'parcels_count',
        'sender_first_name',
        'sender_last_name',
        'sender_phone',
        'emergency_phone',
        'recipient_first_name',
        'recipient_last_name',
        'recipient_country',
        'recipient_phone',
        'parcel_nature_id',
        'declared_value',
        'insurance_opt_in',
        'insurance_rate_applied',
        'insurance_quote_amount',
        'insurance_status',
        'insurance_amount',
        'price_per_kg',
        'freight_subtotal',
        'commission_amount',
        'total_price',
        'currency',
        'dropoff_deadline',
        'confirmation_code',
        'status',
        'payment_status',
        'payment_method',
        'payment_reference',
        'paid_at',
        'refunded_amount',
        'refunded_at',
        'dropped_off_at',
        'shipped_at',
        'arrived_at',
        'completed_at',
        'cancel_reason',
        'cancelled_at',
        'admin_notes',
    ];

    protected $casts = [
        'kg_booked' => 'decimal:2',
        'volume_cbm' => 'decimal:4',
        'parcels_count' => 'integer',
        'declared_value' => 'decimal:2',
        'insurance_opt_in' => 'boolean',
        'insurance_rate_applied' => 'decimal:4',
        'insurance_quote_amount' => 'decimal:2',
        'insurance_amount' => 'decimal:2',
        'price_per_kg' => 'decimal:2',
        'freight_subtotal' => 'decimal:2',
        'commission_amount' => 'decimal:2',
        'total_price' => 'decimal:2',
        'refunded_amount' => 'decimal:2',
        'dropoff_deadline' => 'date',
        'paid_at' => 'datetime',
        'refunded_at' => 'datetime',
        'dropped_off_at' => 'datetime',
        'shipped_at' => 'datetime',
        'arrived_at' => 'datetime',
        'completed_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function departure(): BelongsTo
    {
        return $this->belongsTo(MaritimeDeparture::class, 'maritime_departure_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_user_id');
    }

    public function parcelNature(): BelongsTo
    {
        return $this->belongsTo(ParcelNature::class);
    }

    public function insuranceHistory(): HasMany
    {
        return $this->hasMany(MaritimeBookingHistory::class, 'maritime_booking_id')->oldest();
    }

    public function indemnityClaim(): HasOne
    {
        return $this->hasOne(MaritimeIndemnityClaim::class, 'maritime_booking_id');
    }

    public function conversations(): BelongsToMany
    {
        return $this->belongsToMany(Conversation::class, 'conversation_maritime_booking')
            ->withTimestamps();
    }

    public function senderName(): string
    {
        return trim("{$this->sender_first_name} {$this->sender_last_name}");
    }

    public function recipientName(): string
    {
        return trim("{$this->recipient_first_name} {$this->recipient_last_name}");
    }

    /** Prix du transport payé par l'expéditeur (total hors assurance). */
    public function freightTotal(): float
    {
        return round((float) $this->total_price - ((bool) $this->insurance_opt_in ? (float) $this->insurance_quote_amount : 0), 2);
    }

    public function statusLabel(): string
    {
        return __("maritime.statuses.{$this->status}");
    }

    public function toApi(?User $viewer = null): array
    {
        return [
            'id' => $this->id,
            'transport_mode' => 'sea',
            'maritime_departure_id' => $this->maritime_departure_id,
            'departure' => $this->relationLoaded('departure') && $this->departure ? $this->departure->toApi() : null,
            'kg_booked' => (float) $this->kg_booked,
            'volume_cbm' => $this->volume_cbm !== null ? (float) $this->volume_cbm : null,
            'parcels_count' => $this->parcels_count,
            'sender_first_name' => $this->sender_first_name,
            'sender_last_name' => $this->sender_last_name,
            'sender_phone' => $this->sender_phone,
            'emergency_phone' => $this->emergency_phone,
            'recipient_first_name' => $this->recipient_first_name,
            'recipient_last_name' => $this->recipient_last_name,
            'recipient_country' => $this->recipient_country,
            'recipient_phone' => $this->recipient_phone,
            'parcel_nature' => $this->relationLoaded('parcelNature') && $this->parcelNature ? $this->parcelNature->toApi() : null,
            'declared_value' => (float) $this->declared_value,
            'insurance_opt_in' => (bool) $this->insurance_opt_in,
            'insurance_rate_applied' => (float) $this->insurance_rate_applied,
            'insurance_quote_amount' => (float) $this->insurance_quote_amount,
            'insurance_status' => $this->insurance_status,
            // L'expéditeur voit le prix public ; la commission ASSO n'est pas détaillée.
            'price_per_kg' => (float) $this->kg_booked > 0 ? round($this->freightTotal() / (float) $this->kg_booked, 2) : 0.0,
            'freight_total' => $this->freightTotal(),
            'insurance_amount' => (float) $this->insurance_amount,
            'total_price' => (float) $this->total_price,
            'currency' => $this->currency,
            'dropoff_deadline' => $this->dropoff_deadline?->toDateString(),
            'confirmation_code' => $this->confirmation_code,
            'status' => $this->status,
            'status_label' => $this->statusLabel(),
            'payment_status' => $this->payment_status,
            'payment_method' => $this->payment_method,
            'paid_at' => $this->paid_at?->toIso8601String(),
            'refunded_amount' => $this->refunded_amount !== null ? (float) $this->refunded_amount : null,
            'refunded_at' => $this->refunded_at?->toIso8601String(),
            'dropped_off_at' => $this->dropped_off_at?->toIso8601String(),
            'shipped_at' => $this->shipped_at?->toIso8601String(),
            'arrived_at' => $this->arrived_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'cancel_reason' => $this->cancel_reason,
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'can_report_loss' => $this->canReportLoss($viewer),
            'claim_summary' => $this->claimSummary(),
            'claim_history' => $this->indemnityClaim?->histories->map(fn (MaritimeClaimHistory $history) => [
                'action' => $history->action,
                'from_status' => $history->from_status,
                'to_status' => $history->to_status,
                'reason' => $history->reason,
                'amount_xaf' => $history->amount_xaf !== null ? (float) $history->amount_xaf : null,
                'created_at' => $history->created_at?->toIso8601String(),
            ])->values()->all() ?? [],
            'insurance_history' => $this->insuranceHistory->map(fn (MaritimeBookingHistory $history) => [
                'event' => $history->event,
                'event_label' => $this->historyLabel('maritime.history_events', $history->event),
                'status' => $history->status,
                'status_label' => $this->historyStatusLabel($history->status),
                'reason' => $history->reason,
                'created_at' => $history->created_at?->toIso8601String(),
            ])->values()->all(),
        ];
    }

    private function historyStatusLabel(string $status): string
    {
        $insuranceKey = "maritime.insurance_statuses.{$status}";
        if (Lang::has($insuranceKey)) {
            return __($insuranceKey);
        }

        return $this->historyLabel('maritime.statuses', $status);
    }

    private function historyLabel(string $group, string $value): string
    {
        $key = "{$group}.{$value}";

        return Lang::has($key) ? __($key) : ucfirst(str_replace('_', ' ', $value));
    }

    public function canReportLoss(?User $viewer): bool
    {
        return $viewer !== null
            && (int) $this->sender_user_id === (int) $viewer->id
            && (bool) $this->insurance_opt_in
            && $this->insurance_status === 'active'
            && (float) $this->insurance_amount > 0
            && $this->payment_status === 'completed'
            && in_array($this->status, [self::STATUS_SHIPPED, self::STATUS_ARRIVED], true)
            && ($this->relationLoaded('indemnityClaim')
                ? $this->getRelation('indemnityClaim') === null
                : ! $this->indemnityClaim()->exists());
    }

    private function claimSummary(): ?array
    {
        $claim = $this->indemnityClaim;
        if (! $claim) {
            return null;
        }

        return [
            'id' => $claim->id,
            'status' => $claim->status,
            'reason' => $claim->customer_reason,
            'loss_confirmation_reason' => $claim->loss_confirmation_reason,
            'decision_reason' => $claim->decision_reason,
            'payout_amount_xaf' => $claim->payout_amount_xaf !== null ? (float) $claim->payout_amount_xaf : null,
            'paid_at' => $claim->paid_at?->toIso8601String(),
            'history' => $claim->histories->map(fn (MaritimeClaimHistory $history) => [
                'action' => $history->action,
                'from_status' => $history->from_status,
                'to_status' => $history->to_status,
                'reason' => $history->reason,
                'amount_xaf' => $history->amount_xaf !== null ? (float) $history->amount_xaf : null,
                'created_at' => $history->created_at?->toIso8601String(),
            ])->values()->all(),
        ];
    }
}
