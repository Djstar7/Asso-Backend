<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Course d'un litige, payée par le vendeur : envoi du produit de remplacement (Cas A)
 * ou retour du produit du client vers le vendeur (Cas B).
 *
 * Confiée au livreur partenaire comme une commande : il l'accepte, récupère le colis
 * puis la termine avec le code à 6 chiffres du destinataire (le client pour un
 * remplacement, le vendeur pour un retour).
 */
class DisputeShipment extends Model
{
    public const TYPE_REPLACEMENT = 'replacement';
    public const TYPE_RETURN = 'return';
    public const TYPE_COLLECTION = 'collection';

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

    /** Étape de remise au destinataire : validée seulement avec son code. */
    public const CODE_STEPS = [
        self::TYPE_REPLACEMENT => 'delivered',
        self::TYPE_RETURN => 'delivered_to_vendor',
        self::TYPE_COLLECTION => 'delivered_to_vendor',
    ];

    /** Étape « colis récupéré » enregistrée par le livreur. */
    public const PICKUP_STEPS = [
        self::TYPE_REPLACEMENT => 'shipped',
        self::TYPE_RETURN => 'picked_up',
        self::TYPE_COLLECTION => 'picked_up',
    ];

    protected $fillable = [
        'dispute_id', 'type', 'deliverer_company_id', 'delivery_person_id', 'quote', 'price', 'carrier_amount', 'asso_commission',
        'payer', 'payment_status', 'payment_mode', 'payment_reference', 'payment_currency', 'payment_amount', 'paid_at',
        'accepted_at', 'picked_up_at', 'stops', 'funding_error',
        'status', 'confirmation_code', 'carrier_tracking_number', 'proof_path', 'delivered_at',
    ];

    protected $casts = [
        'quote' => 'array',
        'stops' => 'array',
        'price' => 'decimal:2',
        'carrier_amount' => 'decimal:2',
        'asso_commission' => 'decimal:2',
        'payment_amount' => 'decimal:2',
        'paid_at' => 'datetime',
        'accepted_at' => 'datetime',
        'picked_up_at' => 'datetime',
        'delivered_at' => 'datetime',
    ];

    protected $hidden = ['confirmation_code'];

    protected static function booted(): void
    {
        static::creating(function (DisputeShipment $shipment) {
            $shipment->confirmation_code ??= Order::generateConfirmationCode();
        });
    }

    public function dispute(): BelongsTo { return $this->belongsTo(Dispute::class); }
    public function company(): BelongsTo { return $this->belongsTo(DelivererCompany::class, 'deliverer_company_id'); }
    public function deliveryPerson(): BelongsTo { return $this->belongsTo(User::class, 'delivery_person_id'); }

    /** Étape qui exige le code du destinataire. */
    public function codeStep(): string
    {
        return self::CODE_STEPS[$this->type];
    }

    public function pickupStep(): string
    {
        return self::PICKUP_STEPS[$this->type];
    }

    /** Qui détient le code : le client (remplacement) ou le vendeur (retour). */
    public function codeHolder(): string
    {
        return $this->type !== self::TYPE_REPLACEMENT ? 'vendor' : 'client';
    }

    /** Remis au destinataire (ou retour confirmé). */
    public function isHandedOver(): bool
    {
        return in_array($this->status, [$this->codeStep(), 'confirmed'], true);
    }

    /** Course que le livreur peut prendre ou mener : payée, pas encore remise. */
    public function isOpenForCourier(): bool
    {
        return $this->isPaid() && !$this->isHandedOver();
    }

    public function translationType(): string
    {
        return $this->type === self::TYPE_COLLECTION ? self::TYPE_RETURN : $this->type;
    }

    public function steps(): array
    {
        return $this->type !== self::TYPE_REPLACEMENT ? self::RETURN_STEPS :
            ($this->status === 'awaiting_preparation' ? ['awaiting_preparation' => 'Remplacement à préparer'] + self::REPLACEMENT_STEPS : self::REPLACEMENT_STEPS);
    }

    public function isPaid(): bool
    {
        return $this->payment_status === self::PAYMENT_PAID;
    }

    /**
     * Étapes que l'équipe ASSO peut enregistrer à la main : « retour confirmé » suit
     * automatiquement la remise au vendeur, et la remise elle-même demande le code.
     */
    public function manualSteps(): array
    {
        return array_values(array_diff($this->nextSteps(), ['confirmed']));
    }

    /** Étapes suivantes que l'équipe ASSO (ou le partenaire) peut enregistrer. */
    public function nextSteps(): array
    {
        if (!$this->isPaid()) {
            return [];
        }
        $keys = array_keys($this->steps());
        $index = array_search($this->status, $keys, true);

        return $index === false ? [] : array_slice($keys, $index + 1, 1);
    }

    /**
     * @param string|null $viewer client | vendor : le détenteur du code le voit tant
     *                            que le colis ne lui a pas été remis.
     */
    public function toApi(?string $viewer = null): array
    {
        $showCode = $viewer !== null && $viewer === $this->codeHolder() && $this->isOpenForCourier();

        return [
            'id' => $this->id,
            'type' => $this->type,
            'funding_error' => $viewer === 'vendor' ? $this->funding_error : null,
            'company_id' => $this->deliverer_company_id,
            'company_name' => $this->company?->name ?? ($this->quote['company_name'] ?? null),
            'price' => (float) $this->price,
            'payer' => $this->payer,
            'payment_status' => $this->payment_status,
            'payment_mode' => $this->payment_mode,
            'status' => $this->status,
            'status_label' => isset($this->steps()[$this->status]) ? __("disputes.shipment_steps.{$this->translationType()}.{$this->status}") : $this->status,
            'steps' => collect($this->steps())->map(fn ($label, $key) => ['key' => $key, 'label' => __("disputes.shipment_steps.{$this->translationType()}.{$key}")])->values()->all(),
            'carrier_tracking_number' => $this->carrier_tracking_number,
            'proof_url' => $this->proof_path ? media_url($this->proof_path) : null,
            'paid_at' => $this->paid_at?->toIso8601String(),
            'delivered_at' => $this->delivered_at?->toIso8601String(),
            // À remettre au livreur : il valide la remise avec ce code.
            'confirmation_code' => $showCode ? $this->confirmation_code : null,
            'delivery_person' => $this->deliveryPerson ? [
                'name' => $this->deliveryPerson->name,
                'phone' => $this->deliveryPerson->phone,
            ] : null,
        ];
    }
}
