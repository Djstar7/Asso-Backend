<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Rubrique de la plateforme (« Électronique », « Immobilier »…) confiée à un chef
 * de rubrique. Une rubrique de type `category` regroupe des catégories du
 * catalogue ; `real_estate` porte sur le module Immobilier.
 */
class Division extends Model
{
    public const TYPE_CATEGORY = 'category';

    public const TYPE_REAL_ESTATE = 'real_estate';

    public const TYPES = [
        self::TYPE_CATEGORY => 'Catégories du catalogue',
        self::TYPE_REAL_ESTATE => 'Immobilier',
    ];

    protected $fillable = ['name', 'name_en', 'type', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'division_category')->withTimestamps();
    }

    public function heads(): HasMany
    {
        return $this->hasMany(DivisionHead::class)->latest('started_at');
    }

    /** Chef en poste aujourd'hui. */
    public function currentHead(): HasOne
    {
        return $this->hasOne(DivisionHead::class)->whereNull('ended_at')->latestOfMany('started_at');
    }

    public function commissions(): HasMany
    {
        return $this->hasMany(DivisionCommission::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function isRealEstate(): bool
    {
        return $this->type === self::TYPE_REAL_ESTATE;
    }

    /** Identifiants des catégories couvertes (vide pour l'Immobilier). */
    public function categoryIds(): array
    {
        return $this->isRealEstate() ? [] : $this->categories()->pluck('categories.id')->map(fn ($id) => (int) $id)->all();
    }
}
