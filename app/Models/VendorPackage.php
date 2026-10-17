<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class VendorPackage extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'package_id',
        'storage_total_mb',
        'storage_used_mb',
        'storage_remaining_mb',
        'is_unlimited_storage',
        'purchased_at',
        'expires_at',
        'status',
        'payment_reference',
        'custom_name',
    ];

    protected $casts = [
        'storage_total_mb' => 'decimal:2',
        'storage_used_mb' => 'decimal:2',
        'storage_remaining_mb' => 'decimal:2',
        'is_unlimited_storage' => 'boolean',
        'purchased_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    /**
     * Get the user that owns this vendor package
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the package that this vendor package is based on
     */
    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }

    /**
     * Check if the vendor package is active
     */
    public function isActive(): bool
    {
        return $this->status === 'active' && $this->expires_at > now();
    }

    /**
     * Check if vendor has enough storage space
     */
    public function hasEnoughStorage(float $sizeInMb): bool
    {
        return $this->is_unlimited_storage || $this->storage_remaining_mb >= $sizeInMb;
    }

    /**
     * Deduct storage space from the vendor package.
     * Forfait illimité : l'usage est compté, le restant n'a pas de sens.
     */
    public function deductStorage(float $sizeInMb): void
    {
        DB::transaction(function () use ($sizeInMb) {
            $this->storage_used_mb += $sizeInMb;
            if (!$this->is_unlimited_storage) {
                $this->storage_remaining_mb -= $sizeInMb;
            }
            $this->save();
        });
    }

    /**
     * Add storage space back to the vendor package (when product is deleted)
     */
    public function addStorage(float $sizeInMb): void
    {
        DB::transaction(function () use ($sizeInMb) {
            $this->storage_used_mb = max(0, $this->storage_used_mb - $sizeInMb);
            if (!$this->is_unlimited_storage) {
                $this->storage_remaining_mb = min($this->storage_total_mb, $this->storage_remaining_mb + $sizeInMb);
            }
            $this->save();
        });
    }

    /**
     * Champs de stockage exposés par l'API (tableau de bord, abonnement, boutique).
     * `is_unlimited_storage` : afficher « Illimité » au lieu d'un plafond.
     */
    public function storagePayload(): array
    {
        $total = (float) $this->storage_total_mb;
        $used = (float) $this->storage_used_mb;
        $unlimited = (bool) $this->is_unlimited_storage;

        return [
            'is_unlimited_storage' => $unlimited,
            'storage_total_mb' => $total,
            'storage_used_mb' => $used,
            'storage_remaining_mb' => (float) $this->storage_remaining_mb,
            'storage_percentage_used' => !$unlimited && $total > 0 ? round($used / $total * 100, 2) : 0,
        ];
    }

    /**
     * Scope to get only active vendor packages
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active')
            ->where('expires_at', '>', now());
    }
}
