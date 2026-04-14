<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Order extends Model
{
    protected $fillable = [
        'order_number', 'user_id', 'status', 'subtotal', 'delivery_fee', 'total',
        'delivery_address', 'delivery_latitude', 'delivery_longitude',
        'tracking_number', 'confirmation_code',
        'delivery_person_id', 'delivery_company_id', 'delivery_zone_id',
        'payment_method', 'payment_reference', 'payment_status',
        'notes', 'cancel_reason',
        'confirmed_at', 'shipped_at', 'delivered_at', 'cancelled_at',
        'confirmed_by_client_at', 'confirmed_by_deliverer_at', 'rated_at',
    ];

    protected $casts = [
        'subtotal' => 'decimal:2',
        'delivery_fee' => 'decimal:2',
        'total' => 'decimal:2',
        'confirmed_at' => 'datetime',
        'shipped_at' => 'datetime',
        'delivered_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'confirmed_by_client_at' => 'datetime',
        'confirmed_by_deliverer_at' => 'datetime',
        'rated_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();
        static::creating(function ($order) {
            if (empty($order->order_number)) {
                $order->order_number = 'CMD' . str_pad(random_int(1, 999999), 6, '0', STR_PAD_LEFT);
            }
            if (empty($order->tracking_number)) {
                $order->tracking_number = 'TRK' . strtoupper(substr(md5(uniqid()), 0, 10));
            }
            if (empty($order->confirmation_code)) {
                $order->confirmation_code = static::generateConfirmationCode();
            }
        });
    }

    /**
     * Générer un code secret de confirmation à 6 chiffres
     */
    public static function generateConfirmationCode(): string
    {
        return str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    /**
     * Vérifier si la livraison est confirmée par les deux parties
     */
    public function isDeliveryFullyConfirmed(): bool
    {
        return $this->confirmed_by_client_at !== null && $this->confirmed_by_deliverer_at !== null;
    }

    // Relations

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function deliveryPerson(): BelongsTo { return $this->belongsTo(User::class, 'delivery_person_id'); }
    public function deliveryCompany(): BelongsTo { return $this->belongsTo(DelivererCompany::class, 'delivery_company_id'); }
    public function deliveryZone(): BelongsTo { return $this->belongsTo(DeliveryZone::class, 'delivery_zone_id'); }
    public function items(): HasMany { return $this->hasMany(OrderItem::class); }
    public function rating(): HasOne { return $this->hasOne(OrderRating::class); }

    public function getFormattedTotalAttribute(): string
    {
        return number_format($this->total, 0, ',', ' ') . ' FCFA';
    }
}
