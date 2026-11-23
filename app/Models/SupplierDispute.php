<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Litige ouvert chez le fournisseur pour une commande payée (colis perdu, abîmé, non conforme…). */
class SupplierDispute extends Model
{
    public const EXPECT_REFUND = 1;

    public const EXPECT_RESEND = 2;

    public const EXPECT_LABELS = [self::EXPECT_REFUND => 'Remboursement sur le solde CJ', self::EXPECT_RESEND => 'Renvoi du produit'];

    public const RESULT_LABELS = [1 => 'Remboursé', 2 => 'Produit renvoyé', 3 => 'Refusé par CJ'];

    protected $fillable = [
        'supplier_order_id', 'external_dispute_id', 'business_id', 'reason_id', 'reason_name', 'expect_type',
        'message', 'lines', 'amount_usd', 'status', 'final_result', 'refund_usd', 'resend_order_id',
        'opened_by', 'closed_at', 'last_synced_at', 'last_error',
    ];

    protected $casts = [
        'lines' => 'array',
        'amount_usd' => 'decimal:2',
        'refund_usd' => 'decimal:2',
        'expect_type' => 'integer',
        'final_result' => 'integer',
        'closed_at' => 'datetime',
        'last_synced_at' => 'datetime',
    ];

    public function supplierOrder(): BelongsTo
    {
        return $this->belongsTo(SupplierOrder::class);
    }

    public function opener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function isOpen(): bool
    {
        return $this->closed_at === null;
    }

    public function stateLabel(): string
    {
        if ($this->final_result !== null) {
            return self::RESULT_LABELS[$this->final_result] ?? 'Clos';
        }

        return $this->status ? 'En cours chez CJ ('.$this->status.')' : 'Envoyé à CJ';
    }
}
