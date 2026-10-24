<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DelivererCodeSync extends Model
{
    protected $fillable = [
        'sync_code_id',
        'user_id',
        'company_id', 'delivery_agency_id',
        'is_active',
        'is_online',
        'is_banned',
        'synced_at',
        'unsynced_at',
        'banned_at',
        'banned_by',
        'ban_reason',
    ];

    public function agency(): BelongsTo
    {
        return $this->belongsTo(DeliveryAgency::class, 'delivery_agency_id');
    }

    protected $casts = [
        'is_active' => 'boolean',
        'is_online' => 'boolean',
        'is_banned' => 'boolean',
        'synced_at' => 'datetime',
        'unsynced_at' => 'datetime',
        'banned_at' => 'datetime',
    ];

    /**
     * Get the sync code that this sync belongs to
     */
    public function syncCode(): BelongsTo
    {
        return $this->belongsTo(DelivererSyncCode::class, 'sync_code_id');
    }

    /**
     * Get the user who synced
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the company that was synced
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(DelivererCompany::class, 'company_id');
    }

    /**
     * Get the admin who banned this sync
     */
    public function bannedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'banned_by');
    }

    /**
     * Scope to get only active syncs
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true)->where('is_banned', false);
    }

    /**
     * Scope to get only banned syncs
     */
    public function scopeBanned($query)
    {
        return $query->where('is_banned', true);
    }

    /**
     * Ban this sync
     */
    public function ban($bannedBy, $reason = null)
    {
        $this->update([
            'is_banned' => true,
            'is_active' => false,
            'banned_at' => now(),
            'banned_by' => $bannedBy,
            'ban_reason' => $reason,
        ]);
    }

    /**
     * Unsync (deactivate) this sync
     */
    public function unsync()
    {
        $this->update([
            'is_active' => false,
            'unsynced_at' => now(),
        ]);
    }

    /**
     * Reactivate this sync (if not banned)
     */
    public function reactivate()
    {
        if (!$this->is_banned) {
            $this->update([
                'is_active' => true,
                'unsynced_at' => null,
            ]);
        }
    }
}
