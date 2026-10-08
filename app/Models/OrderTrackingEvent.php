<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Étape datée du suivi d'une commande (cf. App\Services\OrderTrackingService). */
class OrderTrackingEvent extends Model
{
    protected $fillable = [
        'order_id',
        'step',
        'label',
        'location',
        'note',
        'actor_type',
        'actor_id',
        'occurred_at',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function toApi(): array
    {
        return [
            'id' => $this->id,
            'step' => $this->step,
            'label' => \App\Services\OrderTrackingService::stepLabel($this->step) ?? $this->label,
            'location' => $this->location,
            'note' => $this->note,
            'actor_type' => $this->actor_type,
            'occurred_at' => $this->occurred_at?->toIso8601String(),
        ];
    }
}
