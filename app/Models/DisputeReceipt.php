<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DisputeReceipt extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['contest_until' => 'datetime', 'contested_at' => 'datetime', 'accepted_at' => 'datetime'];

    public function shipment()
    {
        return $this->belongsTo(DisputeShipment::class, 'dispute_shipment_id');
    }

    public function evidence()
    {
        return $this->belongsTo(DisputeAttachment::class, 'dispute_attachment_id');
    }
}
