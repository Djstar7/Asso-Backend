<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Fournisseur de dropshipping (CJdropshipping, Printful…).
 *
 * La clé API et les jetons sont chiffrés en base et jamais renvoyés tels quels
 * à l'écran (voir maskedApiKey()).
 */
class Supplier extends Model
{
    public const CJ = 'cj';

    public const PRINTFUL = 'printful';

    protected $fillable = [
        'code', 'name', 'is_active', 'is_sandbox',
        'api_key', 'access_token', 'access_token_expires_at',
        'refresh_token', 'refresh_token_expires_at', 'account_id',
        'webhook_token', 'settings',
        'balance_usd', 'balance_frozen_usd', 'balance_checked_at',
        'connected_at', 'last_error', 'last_error_at',
    ];

    protected $hidden = ['api_key', 'access_token', 'refresh_token', 'webhook_token'];

    protected $casts = [
        'is_active' => 'boolean',
        'is_sandbox' => 'boolean',
        'api_key' => 'encrypted',
        'access_token' => 'encrypted',
        'refresh_token' => 'encrypted',
        'access_token_expires_at' => 'datetime',
        'refresh_token_expires_at' => 'datetime',
        'settings' => 'array',
        'balance_usd' => 'decimal:2',
        'balance_frozen_usd' => 'decimal:2',
        'balance_checked_at' => 'datetime',
        'connected_at' => 'datetime',
        'last_error_at' => 'datetime',
    ];

    /** Ligne du fournisseur, créée au premier appel avec ses réglages par défaut. */
    public static function forCode(string $code): self
    {
        return static::firstOrCreate(
            ['code' => $code],
            [
                'name' => config("suppliers.{$code}.name", Str::upper($code)),
                'is_active' => false,
                'is_sandbox' => true,
                'settings' => config("suppliers.{$code}.defaults", []),
                'webhook_token' => Str::random(48),
            ]
        );
    }

    /** Réglage du fournisseur, sinon valeur par défaut de config/suppliers.php. */
    public function setting(string $key, mixed $default = null): mixed
    {
        return $this->settings[$key]
            ?? config("suppliers.{$this->code}.defaults.{$key}", $default);
    }

    public function hasApiKey(): bool
    {
        return filled($this->api_key);
    }

    /** Clé lisible à l'écran : seulement ses 4 derniers caractères. */
    public function maskedApiKey(): ?string
    {
        if (! $this->hasApiKey()) {
            return null;
        }

        return str_repeat('•', 8).Str::substr($this->api_key, -4);
    }

    public function isConnected(): bool
    {
        return $this->connected_at !== null && filled($this->access_token);
    }

    /** Le solde est-il sous le seuil d'alerte ? */
    public function balanceIsLow(): bool
    {
        return $this->balance_usd !== null
            && (float) $this->balance_usd < (float) $this->setting('balance_alert_usd', 0);
    }

    public function recordError(string $message): void
    {
        $this->forceFill(['last_error' => Str::limit($message, 1000), 'last_error_at' => now()])->save();
    }

    public function clearError(): void
    {
        if ($this->last_error !== null) {
            $this->forceFill(['last_error' => null, 'last_error_at' => null])->save();
        }
    }
}
