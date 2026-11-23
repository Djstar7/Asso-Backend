<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Ligne d'une commande fournisseur : une ligne de la commande ASSO et sa déclinaison chez le fournisseur. */
class SupplierOrderItem extends Model
{
    protected $fillable = [
        'supplier_order_id', 'order_item_id', 'supplier_variant_id', 'external_variant_id',
        'quantity', 'unit_cost_usd', 'unit_freight_usd',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'unit_cost_usd' => 'decimal:2',
        'unit_freight_usd' => 'decimal:2',
    ];

    public function supplierOrder(): BelongsTo
    {
        return $this->belongsTo(SupplierOrder::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function supplierVariant(): BelongsTo
    {
        return $this->belongsTo(SupplierVariant::class);
    }
}
