<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Ligne du compte d'un partenaire : dû (course, fret) ou versement fait par ASSO. */
class PartnerLedgerEntry extends Model
{
    public const TYPE_EARNING = 'earning';
    public const TYPE_PAYOUT = 'payout';

    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    public const PAYMENT_METHODS = [
        'stripe_iban' => 'Virement IBAN (Stripe)',
        'bank_transfer' => 'Virement bancaire',
        'mobile_money' => 'Mobile Money',
        'cash' => 'Espèces',
        'other' => 'Autre',
    ];

    protected $fillable = [
        'deliverer_company_id',
        'type',
        'amount',
        'source_type',
        'source_id',
        'description',
        'payment_method',
        'reference',
        'paid_on',
        'recorded_by',
        'status',
        'payout_amount',
        'payout_currency',
        'stripe_transfer_id',
        'stripe_payout_id',
        'failure_message',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'paid_on' => 'date',
        'payout_amount' => 'decimal:2',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(DelivererCompany::class, 'deliverer_company_id');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
