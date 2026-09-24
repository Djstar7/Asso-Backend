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
