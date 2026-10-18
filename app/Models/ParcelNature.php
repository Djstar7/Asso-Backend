<?php

namespace App\Models;

use App\Models\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Model;

/**
 * Nature d'un colis Diaspo (téléphone, bijoux, document administratif…) et
 * taux d'assurance associé. Voir App\Services\ParcelInsuranceService.
 */
class ParcelNature extends Model
{
    use HasTranslations;

    /** Champs traduisibles (voir HasTranslations). */
    protected array $translatable = ['name'];

    protected $fillable = [
        'name',
        'insurance_rate',
        'min_insurance_amount',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'insurance_rate' => 'decimal:2',
            'min_insurance_amount' => 'decimal:2',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function scopeActiveOrdered($query)
    {
        return $query->where('is_active', true)->orderBy('sort_order')->orderBy('name');
    }

    public function toApi(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'insurance_rate' => (float) $this->insurance_rate,
            'min_insurance_amount' => (float) $this->min_insurance_amount,
            'min_insurance_currency' => 'XAF',
        ];
    }
}
