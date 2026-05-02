<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeliveryPricelist extends Model
{
    protected $fillable = [
        'delivery_zone_id',
        'pricing_type',
        'pricing_data',
        'asso_commission',
        'is_active',
    ];

    protected $casts = [
        'pricing_data' => 'array',
        'asso_commission' => 'float',
        'is_active' => 'boolean',
    ];

    /**
     * Available pricing types
     */
    const PRICING_TYPE_FIXED = 'fixed';
    const PRICING_TYPE_WEIGHT_CATEGORY = 'weight_category';
    const PRICING_TYPE_VOLUMETRIC_WEIGHT = 'volumetric_weight';

    /**
     * Weight categories based on Amazon system
     */
    const WEIGHT_CATEGORIES = [
        'X-small' => 'Très petit produit',
        '30 Deep' => 'Bac profondeur 30 cm',
        '50 Deep' => 'Bac profondeur 50 cm',
        '60 Deep' => 'Bac profondeur 60 cm',
        'Rainbow XL' => 'Grand bac trié (logistique interne)',
        'Pallet' => 'Palette pour gros volume',
    ];

    /**
     * Get the delivery zone that owns this pricelist
     */
    public function deliveryZone(): BelongsTo
    {
        return $this->belongsTo(DeliveryZone::class);
    }

    /**
     * Calculate price based on pricing type
     */
    public function calculatePrice(array $params): float
    {
        switch ($this->pricing_type) {
            case self::PRICING_TYPE_FIXED:
                return $this->pricing_data['price'] ?? 0;

            case self::PRICING_TYPE_WEIGHT_CATEGORY:
                $category = $params['category'] ?? null;
                return $this->pricing_data[$category] ?? 0;

            case self::PRICING_TYPE_VOLUMETRIC_WEIGHT:
                $length = $params['length'] ?? 0;
                $width = $params['width'] ?? 0;
                $height = $params['height'] ?? 0;
                $volumetricWeight = ($length * $width * $height) / 139;

                // Find price based on weight range
                foreach ($this->pricing_data['ranges'] ?? [] as $range) {
                    if ($volumetricWeight >= $range['min'] && $volumetricWeight <= $range['max']) {
                        return $range['price'];
                    }
                }
                return 0;

            default:
                return 0;
        }
    }
}
