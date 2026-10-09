<?php

namespace App\Models;

use App\Models\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Category extends Model
{
    use HasTranslations;

    /** Champs traduisibles (voir HasTranslations). */
    protected array $translatable = ['name', 'description'];

    protected array $translationColumns = ['name' => ['en' => 'name_en']];

    protected $fillable = [
        'name',
        'name_en',
        'slug',
        'description',
        'svg_icon',
        'delivery_days_min',
        'delivery_days_max',
    ];

    protected $casts = [
        'delivery_days_min' => 'integer',
        'delivery_days_max' => 'integer',
    ];

    /**
     * Boot method to auto-generate slug
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($category) {
            if (empty($category->slug)) {
                $category->slug = Str::slug($category->name);
            }
        });

        static::updating(function ($category) {
            if ($category->isDirty('name') && !$category->isDirty('slug')) {
                $category->slug = Str::slug($category->name);
            }
        });
    }

    /**
     * Les boutiques enregistrent leurs catégories par nom français. L'app
     * peut renvoyer le nom affiché (anglais) : on le ramène au nom français.
     *
     * @param  array<int, string>|null  $names
     */
    public static function sourceNames(?array $names): array
    {
        $byName = [];
        foreach (self::query()->withoutGlobalScope('translations')->get(['name', 'name_en']) as $category) {
            $source = $category->getAttributes()['name'];
            $byName[mb_strtolower($source)] = $source;
            if (filled($category->getAttributes()['name_en'] ?? null)) {
                $byName[mb_strtolower($category->getAttributes()['name_en'])] = $source;
            }
        }

        return collect($names ?? [])
            ->map(fn ($name) => $byName[mb_strtolower(trim((string) $name))] ?? $name)
            ->unique()->values()->all();
    }

    /**
     * Noms français enregistrés → noms dans la langue de la requête.
     *
     * @param  array<int, string>|null  $names
     */
    public static function displayNames(?array $names): array
    {
        if (empty($names)) {
            return [];
        }
        $categories = self::query()->whereIn('name', $names)->get()->keyBy(fn ($c) => $c->getAttributes()['name']);

        return collect($names)->map(fn ($name) => isset($categories[$name]) ? $categories[$name]->name : $name)->values()->all();
    }

    /**
     * Get products for this category
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /**
     * Get subcategories for this category
     */
    public function subcategories(): HasMany
    {
        return $this->hasMany(Subcategory::class);
    }
}
