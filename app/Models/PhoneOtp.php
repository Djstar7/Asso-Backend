<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PhoneOtp extends Model
{
    protected $fillable = ['phone', 'code', 'expires_at', 'verified', 'metadata'];

    protected $casts = [
        'expires_at' => 'datetime',
        'verified' => 'boolean',
        'metadata' => 'array',
    ];

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isValidCode(string $code): bool
    {
        return !$this->isExpired() && !$this->verified && $this->code === $code;
    }
}
