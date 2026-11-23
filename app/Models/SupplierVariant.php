<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Déclinaison chez le fournisseur (CJ : vid), reliée à la déclinaison ASSO.
 * product_variant_id reste vide pour un produit à déclinaison unique : la
 * commande passe alors directement par le produit.
 */
class SupplierVariant extends Model
{
    protected $fillable = [
        'supplier_product_id', 'external_id', 'external_sku', 'product_variant_id', 'attributes',
        'cost_usd', 'price_xaf', 'weight_g', 'length_mm', 'width_mm', 'height_mm', 'stock', 'is_active',
    ];

    protected $casts = [
        'attributes' => 'array',
        'cost_usd' => 'decimal:2',
        'price_xaf' => 'decimal:2',
        'weight_g' => 'decimal:2',
        'stock' => 'integer',
        'is_active' => 'boolean',
    ];

    public function supplierProduct(): BelongsTo
    {
        return $this->belongsTo(SupplierProduct::class);
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }
}
