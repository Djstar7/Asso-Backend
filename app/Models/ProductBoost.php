<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Asso Ads — campagne de sponsoring achetée sur un produit.
 *
 * Deux limites simultanées, la première atteinte arrête la campagne :
 *  - le quota d'impressions acheté (`impressions_quota`) ;
 *  - l'échéance (`ends_at`).
 */
class ProductBoost extends Model
{
    public const ACTIVE = 'active';
    public const COMPLETED = 'completed';   // quota d'impressions épuisé
    public const EXPIRED = 'expired';       // échéance atteinte, quota non épuisé
    public const CANCELLED = 'cancelled';   // arrêt manuel (vendeur ou admin)

    protected $fillable = [
        'product_id', 'product_name', 'shop_id', 'user_id', 'package_id', 'package_subscription_id',
        'impressions_quota', 'duration_days', 'amount_xaf',
        'impressions_served', 'clicks',
        'status', 'starts_at', 'ends_at', 'completed_at',
    ];

    protected $casts = [
        'impressions_quota' => 'integer',
        'duration_days' => 'integer',
        'amount_xaf' => 'decimal:2',
        'impressions_served' => 'integer',
        'clicks' => 'integer',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    // ------------------------------------------------------------------
    // Relations
    // ------------------------------------------------------------------

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(PackageSubscription::class, 'package_subscription_id');
    }

    public function dailyStats(): HasMany
    {
        return $this->hasMany(ProductBoostDailyStat::class);
    }

    // ------------------------------------------------------------------
    // Scopes
    // ------------------------------------------------------------------

    /**
     * Campagnes réellement diffusables : statut actif, fenêtre en cours,
     * quota non épuisé. C'est le prédicat utilisé par le feed.
     */
    public function scopeServable(Builder $query): Builder
    {
        return $query->where('status', self::ACTIVE)
            ->where('starts_at', '<=', now())
            ->where('ends_at', '>', now())
            ->whereColumn('impressions_served', '<', 'impressions_quota');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::ACTIVE);
    }

    // ------------------------------------------------------------------
    // État
    // ------------------------------------------------------------------

    public function isServable(): bool
    {
        return $this->status === self::ACTIVE
            && $this->starts_at <= now()
            && $this->ends_at > now()
            && $this->impressions_served < $this->impressions_quota;
    }

    public function remainingImpressions(): int
    {
        return max(0, $this->impressions_quota - $this->impressions_served);
    }

    /** Part du quota consommée, en %. */
    public function progressPercent(): float
    {
        if ($this->impressions_quota <= 0) {
            return 0.0;
        }

        return round(min(100, $this->impressions_served / $this->impressions_quota * 100), 1);
    }

    public function remainingDays(): int
    {
        if ($this->ends_at->isPast()) {
            return 0;
        }

        return (int) ceil(now()->floatDiffInDays($this->ends_at));
    }

    /** Taux de clic : combien d'impressions ont mené à l'ouverture de la fiche. */
    public function clickThroughRate(): float
    {
        if ($this->impressions_served <= 0) {
            return 0.0;
        }

        return round($this->clicks / $this->impressions_served * 100, 2);
    }

    /**
     * Nom de l'article sponsorisé, y compris s'il a été supprimé depuis.
     *
     * Le nom est figé à l'achat : la campagne reste lisible dans l'historique
     * du vendeur et dans la comptabilité, même sans le produit.
     */
    public function productLabel(): string
    {
        return $this->product?->name
            ?? $this->product_name
            ?? 'Article supprimé';
    }

    public function statusLabel(): string
    {
        return in_array($this->status, [self::ACTIVE, self::COMPLETED, self::EXPIRED, self::CANCELLED], true)
            ? __("boosts.status.{$this->status}")
            : ucfirst($this->status);
    }
}
