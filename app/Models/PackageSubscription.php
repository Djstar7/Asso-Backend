<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tentative de paiement d'un abonnement à un package par rail DIRECT.
 * Voir migration create_package_subscriptions_table.
 */
class PackageSubscription extends Model
{
    protected $fillable = [
        'user_id',
        'package_id',
        'payment_method',
        'status',
        'payment_reference',
        'payment_currency',
        'payment_amount',
        'amount_xaf',
        'approval_url',
        'vendor_package_id',
        'paid_at',
        'metadata',
    ];

    protected $casts = [
        'payment_amount' => 'decimal:2',
        'amount_xaf' => 'decimal:2',
        'paid_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }

    public function vendorPackage(): BelongsTo
    {
        return $this->belongsTo(VendorPackage::class);
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }
}
