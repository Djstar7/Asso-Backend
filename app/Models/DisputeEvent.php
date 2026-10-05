<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Historique horodaté d'un litige (chaque action, décision et étape logistique). */
class DisputeEvent extends Model
{
    protected $fillable = ['dispute_id', 'type', 'label', 'note', 'internal', 'actor_type', 'actor_id', 'occurred_at'];

    protected $casts = [
        'internal' => 'boolean',
        'occurred_at' => 'datetime',
    ];

    public function dispute(): BelongsTo { return $this->belongsTo(Dispute::class); }
    public function actor(): BelongsTo { return $this->belongsTo(User::class, 'actor_id'); }

    public function toApi(): array
    {
        return [
            'type' => $this->type,
            'label' => $this->label,
            'note' => $this->note,
            'actor_type' => $this->actor_type,
            'occurred_at' => $this->occurred_at?->toIso8601String(),
        ];
    }
}
