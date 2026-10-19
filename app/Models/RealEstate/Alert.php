<?php

namespace App\Models\RealEstate;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Critères enregistrés par un utilisateur (une alerte par compte). */
class Alert extends Model
{
    protected $table = 'real_estate_alerts';

    protected $fillable = [
        'user_id', 'type', 'applicant_status', 'school', 'city', 'move_in_date',
        'lease_months', 'budget_min', 'budget_max', 'filters', 'is_active', 'last_notified_at',
    ];

    protected $casts = [
        'filters' => 'array',
        'move_in_date' => 'date',
        'is_active' => 'boolean',
        'last_notified_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function toApi(): array
    {
        return [
            'type' => $this->type,
            'applicant_status' => $this->applicant_status,
            'school' => $this->school,
            'city' => $this->city,
            'move_in_date' => $this->move_in_date?->toDateString(),
            'lease_months' => $this->lease_months,
            'budget_min' => $this->budget_min,
            'budget_max' => $this->budget_max,
            'is_active' => (bool) $this->is_active,
        ];
    }
}
