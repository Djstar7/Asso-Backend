<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OtpBypassPhone extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'phone',
        'reason',
        'added_by',
        'is_active',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Get the user who added this bypass phone.
     */
    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by');
    }

    /**
     * Scope a query to only include active bypass phones.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Check if a phone number is allowed to bypass OTP.
     *
     * @param string $phone
     * @return bool
     */
    public static function isAllowedToBypass(string $phone): bool
    {
        return self::active()
            ->where('phone', $phone)
            ->exists();
    }

    /**
     * Normalize phone number format.
     *
     * @param string $phone
     * @return string
     */
    public static function normalizePhone(string $phone): string
    {
        // Remove spaces, dashes, and parentheses
        return preg_replace('/[\s\-\(\)]/', '', $phone);
    }
}
