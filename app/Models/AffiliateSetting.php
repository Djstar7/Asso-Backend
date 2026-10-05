<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class AffiliateSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'is_enabled',
        'max_levels',
        'level_1_percentage',
        'level_2_percentage',
        'level_3_percentage',
        'minimum_withdrawal',
        'auto_approve_commissions',
        'terms_and_conditions',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
        'level_1_percentage' => 'decimal:2',
        'level_2_percentage' => 'decimal:2',
        'level_3_percentage' => 'decimal:2',
        'minimum_withdrawal' => 'decimal:2',
        'auto_approve_commissions' => 'boolean',
    ];

    /**
     * Récupérer les paramètres
     */
    public static function getSettings()
    {
        return static::first() ?? static::create([
            'is_enabled' => true,
            'max_levels' => 2,
            'level_1_percentage' => 10.00,
            'level_2_percentage' => 5.00,
            'level_3_percentage' => 2.50,
            'minimum_withdrawal' => 5000,
            'auto_approve_commissions' => false,
        ]);
    }

    /**
     * Obtenir le pourcentage pour un niveau
     */
    public function getPercentageForLevel(int $level): float
    {
        return match($level) {
            1 => (float) $this->level_1_percentage,
            2 => (float) $this->level_2_percentage,
            3 => (float) $this->level_3_percentage,
            default => 0.0,
        };
    }
}
