<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DiaspoBooking extends Model
{
    protected $fillable = [
        'diaspo_offer_id', 'buyer_user_id', 'seller_user_id',
        'kg_booked', 'price_per_kg', 'subtotal', 'commission_amount', 'total_price', 'currency',
        'status', 'confirmation_code', 'confirmed_by_buyer_at',
        'payment_status', 'payment_reference', 'paid_at', 'refunded_at',
        'conversation_id', 'notes', 'cancel_reason', 'cancelled_at',
    ];

    protected $casts = [
        'kg_booked' => 'decimal:2',
        'price_per_kg' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'commission_amount' => 'decimal:2',
        'total_price' => 'decimal:2',
        'confirmed_by_buyer_at' => 'datetime',
        'paid_at' => 'datetime',
        'refunded_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function offer(): BelongsTo
    {
        return $this->belongsTo(DiaspoOffer::class, 'diaspo_offer_id');
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_user_id');
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_user_id');
    }

    public function getIsCompletedAttribute(): bool
    {
        return $this->status === 'completed';
    }

    private function userPayload(?User $u): ?array
    {
        return $u ? [
            'id' => $u->id,
            'first_name' => $u->first_name,
            'last_name' => $u->last_name,
            'avatar' => $u->avatar,
            'phone' => $u->phone,
        ] : null;
    }

    /** Forme JSON attendue par le mobile (DiaspoBooking.fromJson). */
    public function toApi(): array
    {
        return [
            'id' => $this->id,
            'diaspo_offer_id' => $this->diaspo_offer_id,
            'buyer_user_id' => $this->buyer_user_id,
            'seller_user_id' => $this->seller_user_id,
            'kg_booked' => (float) $this->kg_booked,
            'price_per_kg' => (float) $this->price_per_kg,
            'subtotal' => (float) $this->subtotal,
            'commission_amount' => (float) $this->commission_amount,
            'total_price' => (float) $this->total_price,
            'status' => $this->status,
            'confirmation_code' => $this->confirmation_code,
            'confirmed_by_buyer_at' => $this->confirmed_by_buyer_at?->toIso8601String(),
            'payment_status' => $this->payment_status,
            'payment_reference' => $this->payment_reference,
            'paid_at' => $this->paid_at?->toIso8601String(),
            'refunded_at' => $this->refunded_at?->toIso8601String(),
            'conversation_id' => $this->conversation_id,
            'notes' => $this->notes,
            'cancel_reason' => $this->cancel_reason,
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'formatted_total' => number_format((float) $this->total_price, 0) . ' ' . $this->currency,
            'is_completed' => $this->is_completed,
            'diaspo_offer' => $this->relationLoaded('offer') && $this->offer ? $this->offer->toApi() : null,
            'buyer' => $this->relationLoaded('buyer') ? $this->userPayload($this->buyer) : null,
            'seller' => $this->relationLoaded('seller') ? $this->userPayload($this->seller) : null,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
