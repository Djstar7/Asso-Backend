<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItem extends Model
{
    protected $fillable = [
        'order_id', 'product_id', 'product_name', 'category_id', 'subcategory_id', 'product_variant_id', 'variant_attributes', 'seller_id', 'quantity', 'unit_price', 'total_price',
        'seller_unit_price', 'seller_total_price', 'commission_rate',
        'price_tier_id', 'tier_label',
    ];

    protected $casts = [
        'unit_price' => 'decimal:2',
        'total_price' => 'decimal:2',
        'seller_unit_price' => 'decimal:2',
        'seller_total_price' => 'decimal:2',
        'commission_rate' => 'decimal:2',
        'variant_attributes' => 'array',
    ];

    /**
     * Nom et catégorie du produit figés à la commande : la rubrique d'une vente
     * (chef de rubrique, commissions) ne change plus si la fiche change ensuite.
     */
    protected static function booted(): void
    {
        static::creating(function (OrderItem $item) {
            if ($item->product_id && $item->category_id === null) {
                $product = Product::withTrashed()->withoutGlobalScopes()->find($item->product_id, ['id', 'name', 'category_id', 'subcategory_id']);
                if ($product) {
                    $item->product_name ??= mb_substr((string) $product->getAttributes()['name'], 0, 255);
                    $item->category_id = $product->category_id;
                    $item->subcategory_id ??= $product->subcategory_id;
                }
            }
        });
    }

    public function order(): BelongsTo { return $this->belongsTo(Order::class); }
    // Produit archivé compris : l'historique de la commande reste lisible.
    public function product(): BelongsTo { return $this->belongsTo(Product::class)->withTrashed(); }
    public function variant(): BelongsTo { return $this->belongsTo(ProductVariant::class, 'product_variant_id'); }
    public function seller(): BelongsTo { return $this->belongsTo(User::class, 'seller_id'); }

    /** Restore both the aggregate product stock and its selected variant stock. */
    public function restoreStock(): void
    {
        if ($this->product && $this->product->stock !== null) {
            $this->product->increment('stock', $this->quantity);
        }
        if ($this->product_variant_id && $this->variant) {
            $this->variant->increment('stock', $this->quantity);
        }
    }
}
