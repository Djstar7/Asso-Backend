<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Currency extends Model
{
    protected $fillable = [
        'code',
        'name',
        'symbol',
        'countries',
        'is_active',
    ];

    protected $casts = [
        'countries' => 'array',
        'is_active' => 'boolean',
    ];

    /**
     * Get exchange rates from this currency
     */
    public function exchangeRatesFrom(): HasMany
    {
        return $this->hasMany(ExchangeRate::class, 'from_currency', 'code');
    }

    /**
     * Get exchange rates to this currency
     */
    public function exchangeRatesTo(): HasMany
    {
        return $this->hasMany(ExchangeRate::class, 'to_currency', 'code');
    }

    /**
     * Get the active exchange rate to a specific currency
     */
    public function getRateTo(string $toCurrency): ?float
    {
        $rate = $this->exchangeRatesFrom()
            ->where('to_currency', $toCurrency)
            ->where('is_active', true)
            ->orderBy('effective_date', 'desc')
            ->first();

        return $rate?->rate;
    }

    /**
     * Scope to get active currencies
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
