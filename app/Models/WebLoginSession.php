<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Connexion par QR à l'espace vendeur web, du QR affiché au jeton remis.
 */
class WebLoginSession extends Model
{
    public const PENDING = 'pending';

    public const SCANNED = 'scanned';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const CONSUMED = 'consumed';

    protected $fillable = [
        'public_id', 'secret_hash', 'code_hash', 'status', 'user_id', 'token_id',
        'browser', 'platform', 'ip', 'expires_at', 'scanned_at', 'approved_at', 'consumed_at',
    ];

    protected $hidden = ['secret_hash', 'code_hash'];

    protected $casts = [
        'expires_at' => 'datetime',
        'scanned_at' => 'datetime',
        'approved_at' => 'datetime',
        'consumed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public static function browserFrom(?string $agent): string
    {
        $agent = (string) $agent;

        return match (true) {
            str_contains($agent, 'Edg/') => 'Edge',
            str_contains($agent, 'OPR/') => 'Opera',
            str_contains($agent, 'Firefox/') => 'Firefox',
            str_contains($agent, 'Chrome/') => 'Chrome',
            str_contains($agent, 'Safari/') => 'Safari',
            default => 'Navigateur',
        };
    }

    public static function platformFrom(?string $agent): string
    {
        $agent = (string) $agent;

        return match (true) {
            str_contains($agent, 'Windows') => 'Windows',
            (bool) preg_match('/iPhone|iPad|iPod/', $agent) => 'iOS',
            str_contains($agent, 'Android') => 'Android',
            str_contains($agent, 'Mac OS X') => 'macOS',
            str_contains($agent, 'CrOS') => 'ChromeOS',
            str_contains($agent, 'Linux') => 'Linux',
            default => '—',
        };
    }
}
