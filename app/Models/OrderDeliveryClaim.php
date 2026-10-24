<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderDeliveryClaim extends Model
{
    public const REASONS = ['not_prepared', 'not_shipped', 'not_collected', 'lost', 'overdue', 'not_received'];

    public const REASON_LABELS = ['not_prepared' => 'Préparation non effectuée', 'not_shipped' => 'Expédition manquante',
        'not_collected' => 'Prise en charge manquante', 'lost' => 'Colis perdu', 'overdue' => 'Délai dépassé', 'not_received' => 'Commande non reçue'];

    protected $guarded = ['id'];

    protected $casts = ['client_proofs' => 'array', 'resolved_at' => 'datetime', 'refund_amount' => 'decimal:2'];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}
