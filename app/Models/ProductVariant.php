<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductVariant extends Model
{
    protected $fillable = [
        'sku', 'attributes', 'price_adjustment', 'stock', 'weight', 'is_active', 'sort_order',
    ];

    protected $casts = [
        'attributes' => 'array',
        'price_adjustment' => 'decimal:2',
        'stock' => 'integer',
        'weight' => 'decimal:3',
        'is_active' => 'boolean',
    ];

    /**
     * Poids à facturer pour cette déclinaison, en kg.
     *
     * Retombe sur le poids du produit quand la déclinaison n'en définit pas.
     */
    public function effectiveWeight(): ?float
    {
        return $this->weight !== null
            ? (float) $this->weight
            : ($this->product?->weight !== null ? (float) $this->product->weight : null);
    }

    /**
     * Dimensions du critère « Dimensions » (« 120 × 80 × 100 cm »), en cm. Null pour un
     * autre critère ou une ancienne valeur libre qui n'en donne pas trois.
     *
     * @return array{0: float, 1: float, 2: float}|null
     */
    public function packageDimensions(): ?array
    {
        foreach ($this->getAttribute('attributes') ?? [] as $name => $value) {
            if (mb_strtolower(trim((string) $name)) === 'dimensions'
                && preg_match('/^\s*(\d+(?:[.,]\d+)?)\s*[x×*]\s*(\d+(?:[.,]\d+)?)\s*[x×*]\s*(\d+(?:[.,]\d+)?)\s*(?:cm)?\s*$/iu', (string) $value, $m)) {
                return array_map(fn ($side) => (float) str_replace(',', '.', $side), [$m[1], $m[2], $m[3]]);
            }
        }

        return null;
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
