<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Order extends Model
{
    /**
     * Statuts de paiement (colonne enum `payment_status`). Transitions autorisées :
     *   pending → paid | failed
     *   paid    → refunded (annulation/refus d'une commande déjà encaissée)
     * Aucun retour arrière : un statut final (failed, refunded) ne change plus.
     */
    public const PAYMENT_PENDING = 'pending';
    public const PAYMENT_PAID = 'paid';
    public const PAYMENT_FAILED = 'failed';
    public const PAYMENT_REFUNDED = 'refunded';

    /** Rails encaissés hors solde wallet (Mobile Money / carte). */
    public const DIRECT_PAYMENT_METHODS = ['kpay_direct', 'paypal_direct', 'stripe_direct'];

    protected $fillable = [
        'order_number', 'user_id', 'status', 'subtotal', 'delivery_fee', 'import_shipping_fee', 'base_delivery_price', 'delivery_commission', 'total',
        'sale_commission_rate', 'sale_commission', 'vendor_net_amount', 'settled_at', 'refunded_at',
        'is_wholesale', 'import_country_code', 'shipping_mode', 'shipping_option_id',
        'delivery_address',
        'delivery_address_details',
        'customer_phone',
        'delivery_latitude',
        'delivery_longitude',
        'tracking_number', 'confirmation_code',
        'delivery_person_id', 'delivery_company_id', 'delivery_zone_id',
        'delivery_mode', 'delivery_route_id', 'delivery_city_grid_id', 'delivery_vehicle', 'shipping_weight_kg', 'delivery_vat_amount', 'delivery_breakdown',
        'carrier_tracking_number', 'tracking_status',
        'payment_method', 'payment_reference', 'payment_currency', 'payment_amount', 'payment_status',
        'notes', 'cancel_reason',
        'confirmed_at', 'shipped_at', 'delivered_at', 'cancelled_at',
        'confirmed_by_client_at', 'confirmed_by_deliverer_at', 'rated_at',
    ];

    protected $casts = [
        'subtotal' => 'decimal:2',
        'delivery_fee' => 'decimal:2',
        'import_shipping_fee' => 'decimal:2',
        'base_delivery_price' => 'decimal:2',
        'delivery_commission' => 'decimal:2',
        'total' => 'decimal:2',
        'sale_commission_rate' => 'decimal:2',
        'sale_commission' => 'decimal:2',
        'vendor_net_amount' => 'decimal:2',
        'settled_at' => 'datetime',
        'refunded_at' => 'datetime',
        'confirmed_at' => 'datetime',
        'shipped_at' => 'datetime',
        'delivered_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'confirmed_by_client_at' => 'datetime',
        'confirmed_by_deliverer_at' => 'datetime',
        'rated_at' => 'datetime',
        'shipping_weight_kg' => 'float',
        'delivery_vat_amount' => 'decimal:2',
        'delivery_breakdown' => 'array',
    ];

    /** local = livreur ASSO à domicile (code de confirmation) ; carrier = SOLEX, DHL, FedEx… */
    public const DELIVERY_LOCAL = 'local';
    public const DELIVERY_CARRIER = 'carrier';

    public function isCarrierDelivery(): bool
    {
        return $this->delivery_mode === self::DELIVERY_CARRIER;
    }

    /**
     * Transporteur + livraison à domicile depuis l'agence d'arrivée (ex. SOLEX) : le
     * dernier kilomètre suit le flux urbain (coursier du partenaire, code à 6 chiffres).
     */
    public function hasLastMileDelivery(): bool
    {
        return $this->isCarrierDelivery()
            && ($this->delivery_zone_id !== null || $this->delivery_city_grid_id !== null);
    }

    /**
     * Import en gros livré dans Douala : SOLEX part de l'entrepôt ASSO, sans trajet
     * interurbain. Ailleurs au Cameroun, le colis passe d'abord par un trajet SOLEX.
     */
    public function leavesFromImportHub(): bool
    {
        return $this->is_wholesale && $this->delivery_route_id === null;
    }

    /** Étape qui met le colis à disposition des coursiers du partenaire. */
    public function lastMileStep(): ?string
    {
        if (!$this->hasLastMileDelivery()) {
            return null;
        }

        return $this->leavesFromImportHub() ? 'arrived_hub' : 'arrived';
    }

    /**
     * Commandes transporteur dont le dernier kilomètre peut partir : colis arrivé à
     * l'agence de la ville d'arrivée, ou import arrivé à l'entrepôt ASSO de Douala.
     */
    public function scopeReadyForLastMile($query)
    {
        return $query->where('delivery_mode', self::DELIVERY_CARRIER)
            ->where(fn ($z) => $z->whereNotNull('delivery_zone_id')->orWhereNotNull('delivery_city_grid_id'))
            ->where('status', 'shipped')
            ->where(fn ($t) => $t->whereIn('tracking_status', ['arrived', 'ready_for_pickup'])
                ->orWhere(fn ($h) => $h->where('is_wholesale', true)->whereNull('delivery_route_id')
                    ->where('tracking_status', 'arrived_hub')));
    }

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

    /** Payée hors solde wallet (Mobile Money / carte) ? */
    public function isDirectPayment(): bool
    {
        return in_array($this->payment_method, self::DIRECT_PAYMENT_METHODS, true);
    }

    /** Payée depuis le solde Wallet ASSO (fonds bloqués en escrow à la création) ? */
    public function isWalletPayment(): bool
    {
        return str_starts_with((string) $this->payment_method, 'wallet_');
    }

    // Relations

    /**
     * Prévient chaque vendeur ayant des articles dans la commande.
     */
    public function notifySellers(string $title, string $body, array $data = []): void
    {
        $sellerIds = $this->items()->pluck('seller_id')->filter()->unique();
        foreach (User::whereIn('id', $sellerIds)->get() as $seller) {
            app(\App\Services\FirebaseMessagingService::class)->sendToUser($seller, $title, $body, $data + [
                'order_id' => (string) $this->id,
                'order_number' => (string) $this->order_number,
            ]);
        }
    }

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function deliveryPerson(): BelongsTo { return $this->belongsTo(User::class, 'delivery_person_id'); }
    public function deliveryCompany(): BelongsTo { return $this->belongsTo(DelivererCompany::class, 'delivery_company_id'); }
    public function deliveryZone(): BelongsTo { return $this->belongsTo(DeliveryZone::class, 'delivery_zone_id'); }
    public function deliveryRoute(): BelongsTo { return $this->belongsTo(DeliveryRoute::class, 'delivery_route_id'); }
    public function items(): HasMany { return $this->hasMany(OrderItem::class); }
    public function trackingEvents(): HasMany { return $this->hasMany(OrderTrackingEvent::class)->orderBy('occurred_at')->orderBy('id'); }
    public function rating(): HasOne { return $this->hasOne(OrderRating::class); }

    public function getFormattedTotalAttribute(): string
    {
        return number_format($this->total, 0, ',', ' ') . ' FCFA';
    }
}
