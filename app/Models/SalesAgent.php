<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * P6 — Commercial ASSO identifié par un code unique saisi par le vendeur à la
 * souscription d'un forfait. Voir SalesCommissionService.
 */
class SalesAgent extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'first_name',
        'last_name',
        'email',
        'phone',
        'code',
        'commission_rate',
        'is_active',
        'source',
        'terms_accepted_at',
        'notes',
    ];

    public const SOURCE_ADMIN = 'admin';

    public const SOURCE_SELF = 'self';

    protected $casts = [
        'commission_rate' => 'decimal:2',
        'is_active' => 'boolean',
        'terms_accepted_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function commissions(): HasMany
    {
        return $this->hasMany(SalesCommission::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(PackageSubscription::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function getFullNameAttribute(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    /** Nom exposé au vendeur lors de la vérification du code : « Prénom N. ». */
    public function getDisplayNameAttribute(): string
    {
        $initial = $this->last_name ? ' '.mb_strtoupper(mb_substr($this->last_name, 0, 1)).'.' : '';

        return $this->first_name.$initial;
    }

    /** Normalise un code saisi (espaces retirés, majuscules). */
    public static function normalizeCode(?string $code): string
    {
        return mb_strtoupper(preg_replace('/\s+/', '', (string) $code));
    }

    /** Génère un code libre de la forme ASSO-XXXXX (sans 0/O/1/I pour éviter les confusions). */
    public static function generateCode(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        do {
            $suffix = '';
            for ($i = 0; $i < 5; $i++) {
                $suffix .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $code = 'ASSO-'.$suffix;
        } while (static::withTrashed()->where('code', $code)->exists());

        return $code;
    }

    protected static function booted(): void
    {
        static::saving(function (SalesAgent $agent) {
            $agent->code = $agent->code ? static::normalizeCode($agent->code) : static::generateCode();
        });
    }
}
