<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Pays d'origine d'un produit importé (Chine, Turquie, Dubaï…).
 * Alimente la section "Produits importés" de l'app mobile.
 */
class ImportCountry extends Model
{
    protected $fillable = [
        'code',
        'name',
        'flag',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /** Scope : pays actifs, triés pour l'affichage. */
    public function scopeActiveOrdered($query)
    {
        return $query->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name');
    }
}
