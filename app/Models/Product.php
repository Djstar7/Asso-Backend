<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Product extends Model
{
    /**
     * Weight categories matching DeliveryPricelist system
     */
    const WEIGHT_CATEGORIES = [
        'X-small',
        '30 Deep',
        '50 Deep',
        '60 Deep',
        'Rainbow XL',
        'Pallet',
    ];

    protected $fillable = [
        'shop_id',
        'user_id',
        'category_id',
        'subcategory_id',
        'name',
        'slug',
        'description',
        'price',
        'currency',
        'price_xaf',
        'min_price',
        'max_price',
        'price_type',
        'type',
        'origin_country',
        'stock',
        'weight_category',
        'status',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'price_xaf' => 'decimal:2',
        'min_price' => 'decimal:2',
        'max_price' => 'decimal:2',
    ];

    /**
     * Boot method to auto-generate slug
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($product) {
            if (empty($product->slug)) {
                $product->slug = Str::slug($product->name);
            }
        });

        static::updating(function ($product) {
            if ($product->isDirty('name') && !$product->isDirty('slug')) {
                $product->slug = Str::slug($product->name);
            }
        });

        // Maintenir price_xaf (cache canonique XAF pour tri/filtre) à chaque écriture
        // du prix ou de la devise. Best-effort : si aucun taux fiable n'est disponible,
        // on laisse price_xaf inchangé/null — le montant réellement débité est de toute
        // façon reconverti au taux du moment lors de la commande (OrderService).
        static::saving(function ($product) {
            if (empty($product->currency)) {
                $product->currency = 'XAF';
            }
            $product->currency = strtoupper($product->currency);

            $needsRecompute = $product->isDirty('price')
                || $product->isDirty('currency')
                || $product->price_xaf === null;

            if (!$needsRecompute) {
                return;
            }

            $price = (float) $product->price;
            if ($product->currency === 'XAF') {
                $product->price_xaf = $price;
                return;
            }

            $conv = \App\Services\ExchangeRateService::convert($product->currency, 'XAF', $price);
            if (!empty($conv['success']) && $conv['amount'] !== null) {
                $product->price_xaf = round((float) $conv['amount'], 2);
            }
            // sinon : on ne devine pas — price_xaf reste tel quel (éventuellement null).
        });
    }

    /**
     * Get the user that owns this product
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the shop that owns this product
     */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    /**
     * Get the category of this product
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * Get the subcategory of this product
     */
    public function subcategory(): BelongsTo
    {
        return $this->belongsTo(Subcategory::class);
    }

    /**
     * Get all images for this product
     */
    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('order');
    }

    /**
     * Get the primary image for this product
     */
    public function primaryImage()
    {
        return $this->hasOne(ProductImage::class)->where('is_primary', true);
    }

    /**
     * Get all reviews for this product
     */
    public function reviews(): HasMany
    {
        return $this->hasMany(ProductReview::class);
    }

    /**
     * Get the average rating for this product
     */
    public function getAverageRatingAttribute(): float
    {
        return round($this->reviews()->avg('rating') ?? 0, 1);
    }

    /**
     * Get the total number of reviews
     */
    public function getReviewsCountAttribute(): int
    {
        return $this->reviews()->count();
    }

    /**
     * Symbole de la devise du produit (fallback = code devise, puis 'FCFA' pour XAF).
     */
    public function getCurrencySymbolAttribute(): string
    {
        $code = strtoupper($this->currency ?? 'XAF');
        if ($code === 'XAF' || $code === 'XOF') {
            return 'FCFA';
        }
        $symbol = Currency::where('code', $code)->value('symbol');
        return $symbol ?: $code;
    }

    /**
     * Get formatted price based on price type — dans la devise SOURCE du produit.
     */
    public function getFormattedPriceAttribute(): string
    {
        $symbol = $this->currency_symbol;

        if ($this->price_type === 'variable') {
            return number_format((float) $this->min_price, 0, ',', ' ') . ' - ' . number_format((float) $this->max_price, 0, ',', ' ') . ' ' . $symbol;
        }

        return number_format((float) $this->price, 0, ',', ' ') . ' ' . $symbol;
    }
}