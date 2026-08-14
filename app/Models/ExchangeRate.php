<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExchangeRate extends Model
{
    protected $fillable = [
        'from_currency',
        'to_currency',
        'rate',
        'effective_date',
        'is_active',
        'source',
    ];

    protected $casts = [
        'rate' => 'decimal:8',
        'effective_date' => 'date',
        'is_active' => 'boolean',
    ];

    /**
     * Get the currency this rate is from
     */
    public function fromCurrency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'from_currency', 'code');
    }

    /**
     * Get the currency this rate is to
     */
    public function toCurrency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'to_currency', 'code');
    }

    /**
     * Convert an amount using this exchange rate
     */
    public function convert(float $amount): float
    {
        return $amount * $this->rate;
    }

    /**
     * Scope to get active rates
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to get the latest rate for a currency pair
     */
    public function scopeLatestRate($query, string $from, string $to)
    {
        return $query->where('from_currency', $from)
            ->where('to_currency', $to)
            ->where('is_active', true)
            ->orderBy('effective_date', 'desc')
            ->first();
    }
}
