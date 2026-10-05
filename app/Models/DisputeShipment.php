<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Course d'un litige, payée par le vendeur : envoi du produit de remplacement (Cas A)
 * ou retour du produit du client vers le vendeur (Cas B).
 */
class DisputeShipment extends Model
{
    public const TYPE_REPLACEMENT = 'replacement';
    public const TYPE_RETURN = 'return';

    public const PAYMENT_PENDING = 'pending';
    public const PAYMENT_PAID = 'paid';
    public const PAYMENT_FAILED = 'failed';

    /** Étapes d'un retour (Cas B), de la demande à la confirmation. */
    public const RETURN_STEPS = [
        'awaiting_payment' => 'Retour demandé — paiement de la course par le vendeur',
        'courier_selected' => 'Livreur sélectionné',
        'pickup_scheduled' => 'Collecte programmée',
        'picked_up' => 'Produit récupéré chez le client',
        'in_transit' => 'En retour',
        'delivered_to_vendor' => 'Livré au vendeur',
        'confirmed' => 'Retour confirmé',
    ];

    /** Étapes d'un remplacement (Cas A). */
    public const REPLACEMENT_STEPS = [
        'awaiting_payment' => 'Remplacement autorisé — paiement de la course par le vendeur',
        'courier_selected' => 'Livreur sélectionné',
        'shipped' => 'Produit de remplacement expédié',
        'delivered' => 'Produit de remplacement livré',
    ];

    protected $fillable = [
        'dispute_id', 'type', 'deliverer_company_id', 'quote', 'price', 'carrier_amount', 'asso_commission',
        'payer', 'payment_status', 'payment_mode', 'payment_reference', 'payment_currency', 'payment_amount', 'paid_at',
        'status', 'carrier_tracking_number', 'proof_path', 'delivered_at',
    ];

    protected $casts = [
        'quote' => 'array',
        'price' => 'decimal:2',
        'carrier_amount' => 'decimal:2',
        'asso_commission' => 'decimal:2',
        'payment_amount' => 'decimal:2',
        'paid_at' => 'datetime',
        'delivered_at' => 'datetime',
    ];

    public function dispute(): BelongsTo { return $this->belongsTo(Dispute::class); }
    public function company(): BelongsTo { return $this->belongsTo(DelivererCompany::class, 'deliverer_company_id'); }

    public function steps(): array
    {
        return $this->type === self::TYPE_RETURN ? self::RETURN_STEPS : self::REPLACEMENT_STEPS;
    }

    public function isPaid(): bool
    {
        return $this->payment_status === self::PAYMENT_PAID;
    }

    /** Étapes suivantes que l'équipe ASSO (ou le partenaire) peut enregistrer. */
    public function nextSteps(): array
    {
        if (!$this->isPaid()) {
            return [];
        }
        $keys = array_keys($this->steps());
        $index = array_search($this->status, $keys, true);

        return $index === false ? [] : array_slice($keys, $index + 1);
    }

    public function toApi(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'company_id' => $this->deliverer_company_id,
            'company_name' => $this->company?->name ?? ($this->quote['company_name'] ?? null),
            'price' => (float) $this->price,
            'payer' => $this->payer,
            'payment_status' => $this->payment_status,
            'payment_mode' => $this->payment_mode,
            'status' => $this->status,
            'status_label' => $this->steps()[$this->status] ?? $this->status,
            'steps' => collect($this->steps())->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values()->all(),
            'carrier_tracking_number' => $this->carrier_tracking_number,
            'proof_url' => $this->proof_path ? media_url($this->proof_path) : null,
            'paid_at' => $this->paid_at?->toIso8601String(),
            'delivered_at' => $this->delivered_at?->toIso8601String(),
        ];
    }
}
