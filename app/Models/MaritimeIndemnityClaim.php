<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MaritimeIndemnityClaim extends Model
{
    public const REPORTED = 'reported';

    public const LOSS_CONFIRMED = 'loss_confirmed';

    public const APPROVED = 'approved';

    public const REFUSED = 'refused';

    public const PAID = 'paid';

    protected $fillable = [
        'maritime_booking_id',
        'status',
        'customer_reason',
        'loss_confirmation_reason',
        'decision_reason',
        'payout_amount_xaf',
        'payout_operation_reference',
        'confirmed_by',
        'loss_confirmed_at',
        'reviewed_by',
        'reviewed_at',
        'paid_by',
        'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'payout_amount_xaf' => 'decimal:2',
            'loss_confirmed_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(MaritimeBooking::class, 'maritime_booking_id');
    }

    public function histories(): HasMany
    {
        return $this->hasMany(MaritimeClaimHistory::class, 'maritime_indemnity_claim_id')->oldest();
    }

    public function addHistory(
        string $action,
        ?string $fromStatus,
        string $toStatus,
        ?int $actorId = null,
        ?string $reason = null,
        ?float $amountXaf = null,
        ?string $operationReference = null
    ): MaritimeClaimHistory {
        return $this->histories()->create([
            'action' => $action,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'actor_user_id' => $actorId,
            'reason' => $reason,
            'amount_xaf' => $amountXaf,
            'operation_reference' => $operationReference,
        ]);
    }
}
