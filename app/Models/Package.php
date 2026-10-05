<?php

namespace App\Models;

use App\Models\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Package extends Model
{
    use HasFactory, HasTranslations;

    /** Champs traduisibles (voir HasTranslations). */
    protected array $translatable = ['name', 'description', 'benefits'];

    protected $fillable = [
        'type',
        'name',
        'description',
        'price',
        'duration_days',
        'storage_size_mb',
        'reach_users',
        'benefits',
        'is_active',
        'is_popular',
        'order',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'benefits' => 'array',
        'is_active' => 'boolean',
        'is_popular' => 'boolean',
    ];

    /**
     * Scope pour filtrer par type
     */
    public function scopeOfType($query, $type)
    {
        return $query->where('type', $type);
    }

    /**
     * Scope pour les packages actifs
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope pour les packages populaires
     */
    public function scopePopular($query)
    {
        return $query->where('is_popular', true);
    }

    /**
     * Scope pour ordonner par ordre d'affichage
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('order')->orderBy('price');
    }

    /**
     * Obtenir le libellé du type
     */
    public function getTypeLabelAttribute(): string
    {
        return match($this->type) {
            'storage' => 'Stockage',
            'boost' => 'Boost Sponsoring',
            'certification' => 'Certification',
            default => ucfirst($this->type),
        };
    }

    /**
     * Obtenir l'icône du type
     */
    public function getTypeIconAttribute(): string
    {
        return match($this->type) {
            'storage' => 'fa-hdd',
            'boost' => 'fa-rocket',
            'certification' => 'fa-certificate',
            default => 'fa-box',
        };
    }

    /**
     * Obtenir la couleur du type
     */
    public function getTypeColorAttribute(): string
    {
        return match($this->type) {
            'storage' => 'blue',
            'boost' => 'purple',
            'certification' => 'green',
            default => 'gray',
        };
    }

    /**
     * Formater le prix
     */
    public function getFormattedPriceAttribute(): string
    {
        return number_format($this->price, 0, ',', ' ') . ' FCFA';
    }

    /**
     * Formater la taille de stockage
     */
    public function getFormattedStorageSizeAttribute(): string
    {
        if (!$this->storage_size_mb) return '';

        if ($this->storage_size_mb >= 1024) {
            return __('packages.storage.gb', ['size' => number_format($this->storage_size_mb / 1024, 1)]);
        }

        return __('packages.storage.mb', ['size' => $this->storage_size_mb]);
    }

    /**
     * Formater la durée
     */
    public function getFormattedDurationAttribute(): string
    {
        if ($this->duration_days === 1) {
            return __('packages.duration.day');
        } elseif ($this->duration_days < 30) {
            return __('packages.duration.days', ['count' => $this->duration_days]);
        } elseif ($this->duration_days === 30) {
            return __('packages.duration.month');
        } elseif ($this->duration_days < 365) {
            return __('packages.duration.months', ['count' => round($this->duration_days / 30)]);
        } else {
            return __('packages.duration.years', ['count' => round($this->duration_days / 365)]);
        }
    }
}
