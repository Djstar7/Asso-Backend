<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Affectation d'un chef à une rubrique, avec son pourcentage. Une affectation
 * n'est jamais modifiée : un changement de chef ou de taux la ferme (ended_at) et
 * en ouvre une nouvelle, pour que chaque vente garde le chef et le taux du jour.
 */
class DivisionHead extends Model
{
    protected $fillable = ['division_id', 'user_id', 'commission_rate', 'started_at', 'ended_at', 'created_by', 'end_reason'];

    protected $casts = [
        'commission_rate' => 'decimal:2',
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
    ];

    public function division(): BelongsTo
    {
        return $this->belongsTo(Division::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeCurrent(Builder $query): Builder
    {
        return $query->whereNull('ended_at');
    }

    /** Affectation en poste à une date donnée. */
    public function scopeAt(Builder $query, Carbon $date): Builder
    {
        return $query->where('started_at', '<=', $date)
            ->where(fn ($q) => $q->whereNull('ended_at')->orWhere('ended_at', '>', $date));
    }

    public function isCurrent(): bool
    {
        return $this->ended_at === null;
    }
}
