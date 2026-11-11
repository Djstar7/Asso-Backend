<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Part d'un chef de rubrique sur la commission ASSO d'une vente de sa rubrique.
 *
 * pending     : vente réglée, pas encore acquise (fenêtre de contrôle, litige) ;
 * available   : acquise, versée sur le Wallet du chef ;
 * cancelled   : vente remboursée ou annulée avant versement, rien n'est dû ;
 * clawed_back : remboursée après versement, montant repris (clawback_due = reste
 *               à retenir sur les prochaines commissions).
 */
class DivisionCommission extends Model
{
    public const SOURCE_ORDER_ITEM = 'order_item';

    public const SOURCE_REAL_ESTATE = 'real_estate_reservation';

    public const STATUS_PENDING = 'pending';

    public const STATUS_AVAILABLE = 'available';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_CLAWED_BACK = 'clawed_back';

    public const STATUS_LABELS = [
        self::STATUS_PENDING => 'En attente',
        self::STATUS_AVAILABLE => 'Versée',
        self::STATUS_CANCELLED => 'Annulée',
        self::STATUS_CLAWED_BACK => 'Reprise',
    ];

    protected $fillable = [
        'division_id', 'division_head_id', 'user_id', 'source_type', 'source_id', 'order_id', 'reference',
        'sold_at', 'sale_amount', 'base_amount', 'rate', 'amount', 'currency', 'status',
        'wallet_transaction_id', 'available_at', 'cancelled_at', 'cancel_reason', 'clawback_due',
    ];

    protected $casts = [
        'sold_at' => 'datetime',
        'available_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'sale_amount' => 'decimal:2',
        'base_amount' => 'decimal:2',
        'rate' => 'decimal:2',
        'amount' => 'decimal:2',
        'clawback_due' => 'decimal:2',
    ];

    public function division(): BelongsTo
    {
        return $this->belongsTo(Division::class);
    }

    public function head(): BelongsTo
    {
        return $this->belongsTo(DivisionHead::class, 'division_head_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }
}
