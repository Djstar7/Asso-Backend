<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WalletBalance extends Model
{
    protected $fillable = [
        'user_id',
        'currency',
        'balance',
        'locked_balance',
    ];

    protected $casts = [
        'balance' => 'decimal:2',
        'locked_balance' => 'decimal:2',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Solde disponible (total - bloqué). */
    public function getAvailableAttribute(): float
    {
        return (float) $this->balance - (float) $this->locked_balance;
    }
}
