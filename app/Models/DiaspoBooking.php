<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class DiaspoBooking extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'diaspo_offer_id',
        'buyer_user_id',
        'seller_user_id',
        'kg_booked',
        'price_per_kg',
        'subtotal',
        'commission_amount',
        'total_price',
        'status',
        'confirmation_code',
        'confirmed_by_buyer_at',
        'payment_status',
        'payment_method',
        'payment_reference',
        'paid_at',
        'refunded_at',
        'conversation_id',
        'notes',
        'cancel_reason',
        'cancelled_at',
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

    protected $appends = [
        'formatted_total',
        'is_completed',
    ];

    /**
     * Boot the model
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($booking) {
            // Générer le code de confirmation à 6 chiffres
            if (empty($booking->confirmation_code)) {
                $booking->confirmation_code = static::generateConfirmationCode();
            }
        });
    }

    /**
     * Generate a 6-digit confirmation code
     */
    public static function generateConfirmationCode(): string
    {
        return str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    /**
     * Relations
     */
    public function diaspoOffer(): BelongsTo
    {
        return $this->belongsTo(DiaspoOffer::class);
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_user_id');
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_user_id');
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /**
     * Scopes
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopePaid($query)
    {
        return $query->where('status', 'paid');
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    public function scopeCancelled($query)
    {
        return $query->where('status', 'cancelled');
    }

    public function scopeForBuyer($query, $buyerId)
    {
        return $query->where('buyer_user_id', $buyerId);
    }

    public function scopeForSeller($query, $sellerId)
    {
        return $query->where('seller_user_id', $sellerId);
    }

    /**
     * Accessors
     */
    public function getFormattedTotalAttribute(): string
    {
        return number_format($this->total_price, 2, ',', ' ') . ' EUR';
    }

    public function getIsCompletedAttribute(): bool
    {
        return $this->status === 'completed' && $this->confirmed_by_buyer_at !== null;
    }

    /**
     * Verify confirmation code
     */
    public function verifyConfirmationCode(string $code): bool
    {
        return $this->confirmation_code === $code;
    }

    /**
     * Mark as confirmed by buyer
     */
    public function markAsConfirmedByBuyer(): void
    {
        $this->update([
            'status' => 'completed',
            'confirmed_by_buyer_at' => now(),
        ]);
    }

    /**
     * Cancel booking
     */
    public function cancel(string $reason = null): void
    {
        $this->update([
            'status' => 'cancelled',
            'cancel_reason' => $reason,
            'cancelled_at' => now(),
        ]);
    }

    /**
     * Alias de la relation offre (le flux réservations KPay charge `offer`).
     */
    public function offer(): BelongsTo
    {
        return $this->belongsTo(DiaspoOffer::class, 'diaspo_offer_id');
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

    /**
     * Sérialisation API (contrat mobile) — flux réservations (DiaspoController, KPay direct).
     * NB : le schéma unifié n'a pas de colonne `currency` sur les réservations ; la devise
     * est dérivée de l'offre.
     */
    public function toApi(): array
    {
        $currency = $this->offer?->currency ?? '';

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
            'currency' => $currency,
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
            'formatted_total' => number_format((float) $this->total_price, 0) . ' ' . $currency,
            'is_completed' => $this->is_completed,
            'diaspo_offer' => $this->relationLoaded('offer') && $this->offer ? $this->offer->toApi() : null,
            'buyer' => $this->relationLoaded('buyer') ? $this->userPayload($this->buyer) : null,
            'seller' => $this->relationLoaded('seller') ? $this->userPayload($this->seller) : null,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
