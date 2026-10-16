<?php

namespace App\Models\RealEstate;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Litige sur une réservation immobilière. */
class Dispute extends Model
{
    protected $table = 'real_estate_disputes';

    protected $fillable = [
        'reservation_id', 'reason_id', 'reason_label', 'description', 'payee_response',
        'status', 'decision_note', 'decided_by', 'decided_at',
    ];

    protected $casts = ['decided_at' => 'datetime'];

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(DisputeAttachment::class);
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function toApi(): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'reason' => $this->reason_label,
            'description' => $this->description,
            'payee_response' => $this->payee_response,
            'decision_note' => $this->decision_note,
            'decided_at' => $this->decided_at?->toIso8601String(),
            'attachments' => $this->attachments->map(fn ($a) => [
                'id' => $a->id,
                'author' => $a->author,
                'url' => media_url($a->path),
            ])->values(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
