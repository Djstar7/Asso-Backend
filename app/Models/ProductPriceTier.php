<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Palier de prix « gros » d'un produit importé : un conditionnement (ex. « pack de 12 »)
 * avec son prix unitaire et sa quantité minimale de commande (« cota »).
 */
class ProductPriceTier extends Model
{
    protected $fillable = [
        'product_id',
        'label',
        'unit_price',
        'currency',
        'min_quantity',
        'pack_size',
        'weight_kg',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'unit_price' => 'decimal:2',
        'min_quantity' => 'integer',
        'pack_size' => 'integer',
        'weight_kg' => 'float',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Palier applicable à une quantité : le plus haut dont le seuil est atteint.
     * Sous le premier seuil, le prix du premier palier s'applique : il n'y a
     * pas de minimum de commande.
     *
     * @param  iterable<ProductPriceTier>  $tiers  paliers actifs du produit
     */
    public static function forQuantity(iterable $tiers, int $quantity): ?self
    {
        $sorted = collect($tiers)->sortBy([['min_quantity', 'asc'], ['id', 'asc']])->values();

        return $sorted->last(fn (self $tier) => $tier->min_quantity <= $quantity) ?? $sorted->first();
    }

    public function toApi(): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'unit_price' => (float) $this->unit_price,
            'currency' => $this->currency,
            'min_quantity' => $this->min_quantity,
            'pack_size' => $this->pack_size,
            // Poids d'une unité commandée à ce palier (null : poids du produit).
            'weight_kg' => $this->weight_kg,
            'formatted_price' => number_format((float) $this->unit_price, 0, ',', ' ') . ' ' . $this->currency,
        ];
    }
}
