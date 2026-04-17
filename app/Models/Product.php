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
        'min_price',
        'max_price',
        'price_type',
        'type',
        'stock',
        'weight_category',
        'latitude',
        'longitude',
        'status',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'min_price' => 'decimal:2',
        'max_price' => 'decimal:2',
        'latitude' => 'decimal:8',
        'longitude' => 'decimal:8',
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
     * Get formatted price based on price type
     */
    public function getFormattedPriceAttribute(): string
    {
        if ($this->price_type === 'variable') {
            return number_format($this->min_price, 0, ',', ' ') . ' - ' . number_format($this->max_price, 0, ',', ' ') . ' FCFA';
        }

        return number_format($this->price, 0, ',', ' ') . ' FCFA';
    }
}