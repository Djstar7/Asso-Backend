<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/** Commande passée chez un fournisseur pour une commande ASSO payée. */
class SupplierOrder extends Model
{
    public const AWAITING_APPROVAL = 'awaiting_approval';

    public const SUBMITTING = 'submitting';

    public const NEEDS_REVIEW = 'needs_review';

    public const AWAITING_BALANCE = 'awaiting_balance';

    public const PAID = 'paid';

    public const SHIPPED = 'shipped';

    public const DELIVERED = 'delivered';

    public const CANCELLED = 'cancelled';

    public const SUPPLIER_CANCELLED = 'supplier_cancelled';

    public const FAILED = 'failed';

    public const STATUS_LABELS = [
        self::AWAITING_APPROVAL => 'À valider',
        self::SUBMITTING => 'Envoi en cours',
        self::NEEDS_REVIEW => 'À vérifier',
        self::AWAITING_BALANCE => 'Solde CJ insuffisant',
        self::PAID => 'Payée, en préparation chez CJ',
        self::SHIPPED => 'Expédiée par CJ',
        self::DELIVERED => 'Livrée à l’entrepôt',
        self::CANCELLED => 'Annulée',
        self::SUPPLIER_CANCELLED => 'Annulée par CJ après paiement',
        self::FAILED => 'En erreur',
    ];

    /** États où l'admin peut (re)lancer l'envoi. */
    public const SUBMITTABLE = [self::AWAITING_APPROVAL, self::NEEDS_REVIEW, self::AWAITING_BALANCE, self::FAILED];

    /** États où la commande n'est pas encore payée chez le fournisseur. */
    public const UNPAID = [self::AWAITING_APPROVAL, self::SUBMITTING, self::NEEDS_REVIEW, self::AWAITING_BALANCE, self::FAILED];

    /** États suivis auprès du fournisseur jusqu'à la livraison. */
    public const OPEN = [self::PAID, self::SHIPPED];

    protected $fillable = [
        'supplier_id', 'order_id', 'status', 'is_sandbox', 'external_number', 'external_order_id',
        'shipment_order_id', 'pay_id', 'external_status', 'logistic_name', 'tracking_number', 'tracking_url',
        'expected_cost_usd', 'quoted_cost_usd', 'paid_cost_usd', 'approved_by', 'approved_at',
        'submitted_at', 'paid_at', 'shipped_at', 'delivered_at', 'cancelled_at', 'last_synced_at',
        'last_error', 'payload',
    ];

    protected $casts = [
        'is_sandbox' => 'boolean',
        'expected_cost_usd' => 'decimal:2',
        'quoted_cost_usd' => 'decimal:2',
        'paid_cost_usd' => 'decimal:2',
        'approved_at' => 'datetime',
        'submitted_at' => 'datetime',
        'paid_at' => 'datetime',
        'shipped_at' => 'datetime',
        'delivered_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'last_synced_at' => 'datetime',
        'payload' => 'array',
    ];

    protected $hidden = ['payload'];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /** Commande ASSO, sans le périmètre du back-office (lecture par les tâches). */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class)->withoutGlobalScopes();
    }

    public function items(): HasMany
    {
        return $this->hasMany(SupplierOrderItem::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    public function isSubmittable(): bool
    {
        return in_array($this->status, self::SUBMITTABLE, true);
    }

    public function isUnpaid(): bool
    {
        return in_array($this->status, self::UNPAID, true);
    }

    public function fail(string $status, string $message): void
    {
        $this->forceFill(['status' => $status, 'last_error' => Str::limit($message, 1000)])->save();
    }
}
