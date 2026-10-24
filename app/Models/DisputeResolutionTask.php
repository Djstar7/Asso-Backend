<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DisputeResolutionTask extends Model
{
    public const STAGES = [
        'review_hours' => 'Décision ASSO', 'payment_hours' => 'Financement du transport',
        'receipt_contest_hours' => 'Contestation de réception', 'preparation_hours' => 'Préparation du produit',
        'dispatch_hours' => 'Remise au transporteur', 'local_transit_hours' => 'Transport local',
        'international_transit_hours' => 'Transport international et douane', 'import_preparation_hours' => 'Préparation import',
    ];

    public function stageLabel(): string
    {
        return self::STAGES[$this->stage] ?? 'Traitement ASSO';
    }

    protected $guarded = ['id'];

    protected $casts = ['due_at' => 'datetime', 'escalated_at' => 'datetime', 'completed_at' => 'datetime', 'reminder_due_at' => 'datetime', 'reminded_at' => 'datetime'];

    public function dispute()
    {
        return $this->belongsTo(Dispute::class);
    }

    public function claim()
    {
        return $this->belongsTo(OrderDeliveryClaim::class, 'order_delivery_claim_id');
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }
}
