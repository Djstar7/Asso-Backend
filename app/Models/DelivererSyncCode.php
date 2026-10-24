<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class DelivererSyncCode extends Model
{
    protected $fillable = [
        'user_id',
        'company_id', 'delivery_agency_id',
        'sync_code',
        'is_used',
        'sent_via',
        'sent_at',
        'expires_at',
        'used_at',
    ];

    public function agency(): BelongsTo
    {
        return $this->belongsTo(DeliveryAgency::class, 'delivery_agency_id');
    }

    protected $casts = [
        'is_used' => 'boolean',
        'sent_at' => 'datetime',
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
    ];

    /**
     * Get the user that owns this sync code (after sync)
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the deliverer company that this code is for
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(DelivererCompany::class, 'company_id');
    }

    /**
     * Get all syncs for this code
     */
    public function codeSyncs(): HasMany
    {
        return $this->hasMany(DelivererCodeSync::class, 'sync_code_id');
    }

    /**
     * Get active syncs for this code
     */
    public function activeSyncs(): HasMany
    {
        return $this->hasMany(DelivererCodeSync::class, 'sync_code_id')
            ->where('is_active', true)
            ->where('is_banned', false);
    }

    /**
     * Generate a unique sync code in format: XXXX-XXXX-XXXX (14 chars)
     * Format optimized for WhatsApp template (max 15 chars)
     */
    public static function generateSyncCode(): string
    {
        do {
            $code = sprintf(
                '%s-%s-%s',
                strtoupper(Str::random(4)),
                strtoupper(Str::random(4)),
                strtoupper(Str::random(4))
            );
        } while (self::where('sync_code', $code)->exists());

        return $code;
    }

    /**
     * Check if the code is expired
     */
    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    /**
     * Check if the code is valid (only check expiration, not usage)
     * With the new multi-sync system, codes can be reused by multiple users
     */
    public function isValid(): bool
    {
        return !$this->isExpired();
    }

    /**
     * Mark the code as used
     */
    public function markAsUsed(): void
    {
        $this->update([
            'is_used' => true,
            'used_at' => now(),
        ]);
    }
}
