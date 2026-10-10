<?php

namespace App\Services;

use App\Models\DelivererCompany;
use App\Models\Dispute;
use App\Models\DisputeAttachment;
use App\Models\DisputeEvent;
use App\Models\DisputeShipment;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Réclamations & litiges (document « Gestion des réclamations & litiges »).
 *
 * Parcours : produit livré → 48 h de contrôle (part vendeur bloquée) → réclamation sur
 * un article → analyse et décision ASSO.
 *  - Non fondée : la part de l'article est débloquée, le litige est clos.
 *  - Fondée, Cas A : le vendeur remplace le produit (une seule fois) et paie la course ;
 *    nouveau contrôle de 48 h à la livraison du remplacement. Encore non conforme → Cas B.
 *  - Fondée, Cas B : retour du produit payé par le vendeur, puis remboursement du client
 *    sur son Wallet ASSO ; la part du vendeur n'est pas versée. Le client se voit ensuite
 *    proposer des produits similaires dont le VENDEUR offre la livraison.
 *
 * Le vendeur paie les courses comme un client (Wallet, Mobile Money / OM, carte) ; le
 * processus reprend dès que le paiement est confirmé. Import en gros (ASSO China,
 * Türkiye, Dubai) : ASSO est le revendeur, il gère et paie lui-même depuis le back-office.
 */
class DisputeService
{
    /** Préfixe de la référence KPay d'une course de litige (webhook PaymentController). */
    public const KPAY_PREFIX = 'LITIGE-';

    public function __construct(
        private OrderService $orders,
        private WalletService $wallet,
        private FirebaseMessagingService $fcm,
    ) {
    }

    // ─────────────────────────────────────────────────────────────────────
    // Ouverture et analyse
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Le client signale un problème sur un article, pendant la fenêtre de 48 h. La part
     * vendeur de l'article passe de la commande au litige (toujours bloquée).
     *
     * @param UploadedFile[] $photos
     * @throws \Exception
     */
    public function open(Order $order, int $orderItemId, User $client, string $reason, string $description, array $photos = []): Dispute
    {
        if ($order->user_id !== $client->id) {
            throw new \Exception(__('disputes.not_found'));
        }

        $paths = $this->storePhotos($photos);

        $dispute = DB::transaction(function () use ($order, $orderItemId, $client, $reason, $description, $paths) {
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if (!$locked->isInControlWindow()) {
                throw new \Exception(__('disputes.window_closed'));
            }
            $item = OrderItem::where('order_id', $locked->id)->whereKey($orderItemId)->first();
            if (!$item) {
                throw new \Exception(__('disputes.item_not_found'));
            }
            if (Dispute::where('order_item_id', $item->id)->exists()) {
                throw new \Exception(__('disputes.already_opened'));
            }

            $share = min(self::itemVendorShare($locked, $item), (float) $locked->vendor_funds_held_amount);
            $share = max(0.0, round($share, 2));
            $locked->update(['vendor_funds_held_amount' => round((float) $locked->vendor_funds_held_amount - $share, 2)]);

            $dispute = Dispute::create([
                'order_id' => $locked->id,
                'order_item_id' => $item->id,
                'product_id' => $item->product_id,
                'client_id' => $client->id,
                'seller_id' => $item->seller_id,
                'reason' => $reason,
                'description' => $description,
                'held_amount' => $share,
                'status' => Dispute::STATUS_NEW,
            ]);
            foreach ($paths as $path) {
                $dispute->attachments()->create(['author_type' => 'client', 'author_id' => $client->id, 'path' => $path]);
            }
            $this->event($dispute, 'opened', __('disputes.events.opened', [], 'fr'), $description, 'client', $client->id);

            return $dispute;
        });

        $this->notifySeller($dispute, 'dispute_opened', ['number' => $dispute->number, 'order_number' => $order->order_number]);

        return $dispute;
    }

    /** L'équipe ASSO prend le dossier. */
    public function markInReview(Dispute $dispute, User $employee): Dispute
    {
        if ($dispute->status !== Dispute::STATUS_NEW) {
            return $dispute;
        }
        $dispute->update(['status' => Dispute::STATUS_IN_REVIEW]);
        $this->event($dispute, 'in_review', __('disputes.events.in_review', [], 'fr'), null, 'admin', $employee->id);

        return $dispute;
    }

    /** ASSO demande au vendeur ses explications et preuves. */
    public function contactVendor(Dispute $dispute, User $employee, ?string $note): Dispute
    {
        $this->assertNotDecided($dispute);
        $dispute->update(['status' => Dispute::STATUS_VENDOR_CONTACTED]);
        $this->event($dispute, 'vendor_contacted', __('disputes.events.vendor_contacted', [], 'fr'), $note, 'admin', $employee->id);
        $this->notifySeller($dispute, 'dispute_vendor_contacted', ['number' => $dispute->number]);

        return $dispute;
    }

    /**
     * Observations et preuves du vendeur (ou pièce ajoutée par ASSO).
     *
     * @param UploadedFile[] $files
     */
    public function addEvidence(Dispute $dispute, string $authorType, User $author, ?string $note, array $files = []): Dispute
    {
        if (!$dispute->isOpen()) {
            throw new \Exception(__('disputes.closed'));
        }
        foreach ($this->storePhotos($files) as $path) {
            $dispute->attachments()->create(['author_type' => $authorType, 'author_id' => $author->id, 'path' => $path]);
        }
        $label = $authorType === 'vendor' ? __('disputes.events.vendor_reply', [], 'fr') : __('disputes.events.asso_evidence', [], 'fr');
        $this->event($dispute, 'evidence', $label, $note, $authorType, $author->id);

        return $dispute;
    }

    /** Note interne de l'équipe ASSO (jamais visible du client ni du vendeur). */
    public function addInternalNote(Dispute $dispute, User $employee, string $note): void
    {
        $this->event($dispute, 'note', __('disputes.events.internal_note', [], 'fr'), $note, 'admin', $employee->id, internal: true);
    }

    /**
     * Décision finale d'ASSO. Non fondée : part débloquée, litige clos. Fondée : le
     * vendeur peut remplacer (une fois) ou le retour est organisé.
     */
    public function decide(Dispute $dispute, User $employee, string $decision, string $note): Dispute
    {
        $this->assertNotDecided($dispute);

        if ($decision === Dispute::DECISION_UNFOUNDED) {
            DB::transaction(function () use ($dispute, $employee, $note) {
                $locked = Dispute::whereKey($dispute->id)->lockForUpdate()->firstOrFail();
                $this->releaseDisputeFunds($locked, 'unfounded');
                $locked->update([
                    'decision' => Dispute::DECISION_UNFOUNDED,
                    'decision_note' => $note,
                    'decided_by' => $employee->id,
                    'decided_at' => now(),
                    'status' => Dispute::STATUS_REJECTED,
                    'closed_at' => now(),
                ]);
                $this->event($locked, 'decision', __('disputes.events.decision_unfounded', [], 'fr'), $note, 'admin', $employee->id);
            });
            $dispute->refresh();
            $this->notifyClient($dispute, 'dispute_unfounded', ['number' => $dispute->number]);
            $this->notifySeller($dispute, 'dispute_unfounded_vendor', ['number' => $dispute->number]);

            return $dispute;
        }

        $dispute->update([
            'decision' => Dispute::DECISION_FOUNDED,
            'decision_note' => $note,
            'decided_by' => $employee->id,
            'decided_at' => now(),
            'status' => Dispute::STATUS_VENDOR_CONTACTED,
        ]);
        $this->event($dispute, 'decision', __('disputes.events.decision_founded', [], 'fr'), $note, 'admin', $employee->id);
        $this->notifyClient($dispute, 'dispute_founded', ['number' => $dispute->number]);
        $this->notifySeller($dispute, 'dispute_founded_vendor', ['number' => $dispute->number]);

        return $dispute;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Cas A : remplacement / Cas B : retour
    // ─────────────────────────────────────────────────────────────────────

    /** Le vendeur (ou ASSO pour un import) lance le remplacement : une seule fois. */
    public function startReplacement(Dispute $dispute, string $actorType, int $actorId): DisputeShipment
    {
        return DB::transaction(function () use ($dispute, $actorType, $actorId) {
            $locked = Dispute::whereKey($dispute->id)->lockForUpdate()->firstOrFail();
            if ($locked->decision !== Dispute::DECISION_FOUNDED || $locked->status !== Dispute::STATUS_VENDOR_CONTACTED) {
                throw new \Exception(__('disputes.action_unavailable'));
            }
            if ($locked->replacement_count >= Dispute::MAX_REPLACEMENTS) {
                throw new \Exception(__('disputes.replacement_already_used'));
            }

            $locked->update([
                'status' => Dispute::STATUS_REPLACEMENT,
                'resolution' => Dispute::RESOLUTION_REPLACEMENT,
                'replacement_count' => $locked->replacement_count + 1,
                'auto_validate_at' => null,
            ]);
            $shipment = $this->createShipment($locked, DisputeShipment::TYPE_REPLACEMENT);
            $this->event($locked, 'replacement', __('disputes.events.replacement_authorized', [], 'fr'), null, $actorType, $actorId);

            return $shipment;
        });
    }

    /**
     * Cas B : retour du produit et remboursement. Lancé par le vendeur (remplacement
     * impossible), par ASSO, ou imposé après un remplacement encore non conforme. Le
     * vendeur est notifié qu'il doit payer la course de retour.
     */
    public function startReturn(Dispute $dispute, string $actorType, ?int $actorId, ?string $note = null): DisputeShipment
    {
        $shipment = DB::transaction(function () use ($dispute, $actorType, $actorId, $note) {
            $locked = Dispute::whereKey($dispute->id)->lockForUpdate()->firstOrFail();
            $afterReplacement = $locked->status === Dispute::STATUS_REPLACEMENT;
            $allowed = $locked->decision === Dispute::DECISION_FOUNDED
                && in_array($locked->status, [Dispute::STATUS_VENDOR_CONTACTED, Dispute::STATUS_REPLACEMENT], true);
            if (!$allowed) {
                throw new \Exception(__('disputes.action_unavailable'));
            }
            if ($afterReplacement && !$this->replacementDelivered($locked)) {
                throw new \Exception(__('disputes.replacement_in_progress'));
            }

            $locked->update([
                'status' => Dispute::STATUS_RETURN,
                'resolution' => Dispute::RESOLUTION_RETURN_REFUND,
                'auto_validate_at' => null,
            ]);
            $shipment = $this->createShipment($locked, DisputeShipment::TYPE_RETURN);
            $this->event(
                $locked,
                'return',
                $afterReplacement
                    ? __('disputes.events.return_after_replacement', [], 'fr')
                    : __('disputes.events.return_case_b', [], 'fr'),
                $note,
                $actorType,
                $actorId,
            );

            return $shipment;
        });

        $dispute->refresh();
        $this->notifyClient($dispute, 'dispute_return_validated', ['number' => $dispute->number]);
        if ($shipment->payer === 'vendor') {
            $this->notifySeller($dispute, 'dispute_shipment_payment_required', [
                'number' => $dispute->number,
            ], ['shipment_id' => (string) $shipment->id]);
        }

        return $shipment;
    }

    /** Le client valide le produit de remplacement : part débloquée, litige résolu. */
    public function confirmReplacement(Dispute $dispute, User $client): Dispute
    {
        DB::transaction(function () use ($dispute, $client) {
            $locked = Dispute::whereKey($dispute->id)->lockForUpdate()->firstOrFail();
            if ($locked->client_id !== $client->id || !$locked->isInReplacementControl()) {
                throw new \Exception(__('disputes.action_unavailable'));
            }
            $this->resolveReplacement($locked, 'client', $client->id, __('disputes.events.replacement_conform', [], 'fr'));
        });

        return $dispute->refresh();
    }

    /**
     * Le client signale à nouveau un problème sur le remplacement : aucun second
     * remplacement, le retour (Cas B) est imposé.
     *
     * @param UploadedFile[] $photos
     */
    public function reportReplacement(Dispute $dispute, User $client, string $description, array $photos = []): DisputeShipment
    {
        if ($dispute->client_id !== $client->id || !$dispute->isInReplacementControl()) {
            throw new \Exception(__('disputes.action_unavailable'));
        }
        foreach ($this->storePhotos($photos) as $path) {
            $dispute->attachments()->create(['author_type' => 'client', 'author_id' => $client->id, 'path' => $path]);
        }
        $this->event($dispute, 'replacement_rejected', __('disputes.events.replacement_rejected', [], 'fr'), $description, 'client', $client->id);

        return $this->startReturn($dispute, 'system', null);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Courses payées par le vendeur
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Partenaires de livraison disponibles pour la course (même calcul qu'une commande,
     * entre la boutique et l'adresse du client ; le trajet retour coûte autant).
     */
    public function partnerQuotes(DisputeShipment $shipment): array
    {
        $dispute = $shipment->dispute;
        $order = $dispute->order;

        return app(DeliveryQuoteService::class)->quotes(
            $this->quoteItems($dispute),
            $order->delivery_latitude !== null ? (float) $order->delivery_latitude : null,
            $order->delivery_longitude !== null ? (float) $order->delivery_longitude : null,
            null,
            null,
            null,
            $order->delivery_address,
        );
    }

    /** Partenaire choisi : prix recalculé côté serveur et figé sur la course. */
    public function choosePartner(DisputeShipment $shipment, array $choice): DisputeShipment
    {
        if ($shipment->isPaid() || $shipment->payment_reference) {
            throw new \Exception(__('disputes.shipment_already_paid'));
        }
        $dispute = $shipment->dispute;
        $order = $dispute->order;

        $quote = app(DeliveryQuoteService::class)->quoteFor(
            $this->quoteItems($dispute),
            (int) $choice['company_id'],
            isset($choice['zone_id']) ? (int) $choice['zone_id'] : null,
            isset($choice['route_id']) ? (int) $choice['route_id'] : null,
            $order->delivery_latitude !== null ? (float) $order->delivery_latitude : null,
            $order->delivery_longitude !== null ? (float) $order->delivery_longitude : null,
            null,
            null,
            isset($choice['grid_id']) ? (int) $choice['grid_id'] : null,
            $choice['vehicle'] ?? null,
            null,
            $order->delivery_address,
        );

        $shipment->update([
            'deliverer_company_id' => $quote['company_id'],
            'quote' => array_intersect_key($quote, array_flip([
                'company_id', 'company_name', 'zone_id', 'route_id', 'grid_id', 'vehicle',
                'service_type_label', 'service_mode_label', 'route_label', 'lead_time', 'delivery_option_label',
            ])),
            'price' => (float) $quote['delivery_price'],
            'carrier_amount' => (float) $quote['base_price'],
            'asso_commission' => (float) $quote['asso_commission'],
            'payment_status' => DisputeShipment::PAYMENT_PENDING,
        ]);

        return $shipment->refresh();
    }

    /**
     * Le vendeur paie la course. Wallet : débité et confirmé aussitôt ; Mobile Money /
     * carte : confirmé par le webhook ou le polling.
     *
     * @param string $mode wallet | kpay_direct | stripe_direct
     */
    public function initiatePayment(DisputeShipment $shipment, User $vendor, string $mode, ?string $provider = null, ?string $phone = null): DisputeShipment
    {
        $shipment = DB::transaction(function () use ($shipment, $vendor, $mode, $provider, $phone) {
            $locked = DisputeShipment::whereKey($shipment->id)->lockForUpdate()->firstOrFail();
            if ($locked->payer !== 'vendor') {
                throw new \Exception(__('disputes.action_unavailable'));
            }
            if ($locked->isPaid()) {
                throw new \Exception(__('disputes.shipment_already_paid'));
            }
            if (!$locked->deliverer_company_id || (float) $locked->price <= 0) {
                throw new \Exception(__('disputes.choose_partner_first'));
            }
            $amount = (float) $locked->price;
            $number = $locked->dispute->number;

            if ($mode === 'wallet') {
                $this->wallet->debit(
                    $vendor,
                    $amount,
                    WalletTransaction::label('dispute_delivery_'.$this->shipmentKind($locked), ['number' => $number]),
                    'dispute_shipment',
                    $locked->id,
                    ['dispute_id' => $locked->dispute_id],
                    'kpay'
                );
                $locked->update(['payment_mode' => 'wallet', 'payment_reference' => null]);

                return $locked;
            }

            $locked->update(['payment_mode' => $mode, 'payment_reference' => null, 'payment_status' => DisputeShipment::PAYMENT_PENDING]);

            if ($mode === 'kpay_direct') {
                $currency = KPayCatalog::currencyForProvider($provider);
                $payAmount = (float) round($amount);
                if ($currency !== 'XAF') {
                    $converted = ExchangeRateService::convertAmount('XAF', $currency, $amount);
                    if ($converted === null) {
                        throw new \Exception(__('payments.conversion_unavailable_retry', ['currency' => $currency]));
                    }
                    $payAmount = (float) round($converted);
                }
                $result = app(MobileMoneyGateway::class)->initializePayment([
                    'amount' => $payAmount,
                    'provider' => $provider,
                    'phone_number' => $phone,
                    'description' => "Livraison litige {$number}",
                    'external_reference' => self::KPAY_PREFIX . $locked->id,
                ]);
                if (empty($result['success'])) {
                    throw new \Exception($result['message'] ?? __('payments.kpay_init_failed'));
                }
                $locked->update([
                    'payment_reference' => $result['id'] ?? (self::KPAY_PREFIX . $locked->id),
                    'payment_currency' => $currency,
                    'payment_amount' => $payAmount,
                ]);

                return $locked;
            }

            // stripe_direct : carte native (Payment Sheet), confirmée par polling / webhook.
            $stripe = app(StripeService::class);
            if (!$stripe->isConfigured()) {
                throw new \Exception(__('payments.card_temporarily_unavailable'));
            }
            $currency = PaymentMethodService::currencyFor('stripe') ?? 'USD';
            $stripeAmount = strtoupper($currency) === 'XAF'
                ? (float) round($amount)
                : ExchangeRateService::convertAmount('XAF', $currency, $amount);
            if ($stripeAmount === null) {
                throw new \Exception(__('payments.card_conversion_unavailable', ['currency' => $currency]));
            }
            $intent = $stripe->createPaymentIntent((float) $stripeAmount, $currency, [
                'asso_kind' => 'dispute_shipment',
                'shipment_id' => (string) $locked->id,
            ]);
            if (empty($intent['id']) || empty($intent['client_secret'])) {
                throw new \Exception(__('payments.stripe_init_failed'));
            }
            $locked->update([
                'payment_reference' => $intent['id'],
                'payment_currency' => strtoupper($currency),
                'payment_amount' => round((float) $stripeAmount, 2),
            ]);
            $locked->client_secret = $intent['client_secret'];
            $locked->payment_intent_id = $intent['id'];
            $locked->stripe_publishable_key = $intent['publishable_key'] ?? null;

            return $locked;
        });

        if ($mode === 'wallet') {
            $this->confirmPayment($shipment);
        }

        return $shipment;
    }

    /** Import en gros : ASSO, revendeur, prend la course à sa charge (back-office). */
    public function payByAsso(DisputeShipment $shipment, User $employee): void
    {
        if ($shipment->payer !== 'asso') {
            throw new \Exception(__('disputes.action_unavailable'));
        }
        if (!$shipment->deliverer_company_id) {
            throw new \Exception(__('disputes.choose_partner_first'));
        }
        $shipment->update(['payment_mode' => 'asso']);
        $this->confirmPayment($shipment, $employee);
    }

    /**
     * Course payée (idempotent) : le partenaire et ASSO sont crédités, la course
     * démarre (livreur sélectionné) et le processus reprend.
     */
    public function confirmPayment(DisputeShipment $shipment, ?User $employee = null): void
    {
        $paid = DB::transaction(function () use ($shipment) {
            $locked = DisputeShipment::whereKey($shipment->id)->lockForUpdate()->with('dispute.order')->first();
            if (!$locked || $locked->isPaid() || !$locked->payment_mode) {
                return null;
            }
            $dispute = $locked->dispute;

            // Rail direct : trace dans l'historique du vendeur (solde Wallet inchangé).
            if (in_array($locked->payment_mode, ['kpay_direct', 'stripe_direct'], true) && $dispute->seller_id) {
                $balance = (float) (User::find($dispute->seller_id)?->kpayBalanceFor('XAF') ?? 0);
                WalletTransaction::create([
                    'user_id' => $dispute->seller_id,
                    'type' => 'debit',
                    'amount' => (float) $locked->price,
                    'balance_before' => $balance,
                    'balance_after' => $balance,
                    'description' => WalletTransaction::label('dispute_delivery_'.$this->shipmentKind($locked), ['number' => $dispute->number]),
                    'reference_type' => 'dispute_shipment',
                    'reference_id' => $locked->id,
                    'metadata' => ['payment_method' => $locked->payment_mode, 'payment_reference' => $locked->payment_reference],
                    'status' => 'completed',
                    'provider' => $locked->payment_mode === 'stripe_direct' ? 'stripe' : 'kpay',
                ]);
            }

            $companyUserId = DelivererCompany::whereKey($locked->deliverer_company_id)->value('user_id');
            if ($companyUserId && (float) $locked->carrier_amount > 0 && ($companyUser = User::find($companyUserId))) {
                $this->wallet->credit(
                    $companyUser,
                    (float) $locked->carrier_amount,
                    null,
                    WalletTransaction::label('dispute_course_'.$this->shipmentKind($locked), ['number' => $dispute->number]),
                    ['dispute_shipment_id' => $locked->id],
                    'kpay'
                );
            }
            if ($locked->payer === 'vendor' && (float) $locked->asso_commission > 0 && ($platform = CommissionService::platformAccount())) {
                $this->wallet->credit(
                    $platform,
                    (float) $locked->asso_commission,
                    null,
                    WalletTransaction::label('dispute_delivery_commission', ['number' => $dispute->number]),
                    ['dispute_shipment_id' => $locked->id],
                    'kpay'
                );
            }

            $locked->update([
                'payment_status' => DisputeShipment::PAYMENT_PAID,
                'paid_at' => now(),
                'status' => 'courier_selected',
            ]);
            $this->event(
                $dispute,
                'shipment_paid',
                __('disputes.events.' . ($locked->type === DisputeShipment::TYPE_RETURN ? 'return' : 'replacement')
                    . ($locked->payer === 'asso' ? '_paid_asso' : '_paid_vendor'), [], 'fr'),
                $locked->company?->name,
                $locked->payer === 'asso' ? 'admin' : 'vendor',
                $locked->payer === 'asso' ? null : $dispute->seller_id,
            );

            return $locked;
        });

        if (!$paid) {
            return;
        }

        $dispute = $paid->dispute;
        if ($paid->type === DisputeShipment::TYPE_RETURN) {
            $this->notifyClient($dispute, 'dispute_return_courier', ['number' => $dispute->number]);
        }
        $this->notifySeller($dispute, 'dispute_shipment_paid', ['number' => $dispute->number]);
    }

    /** Paiement non abouti : le vendeur peut relancer. */
    public function failPayment(DisputeShipment $shipment): void
    {
        if ($shipment->isPaid()) {
            return;
        }
        $shipment->update([
            'payment_status' => DisputeShipment::PAYMENT_FAILED,
            'payment_mode' => null,
            'payment_reference' => null,
            'payment_currency' => null,
            'payment_amount' => null,
        ]);
        $dispute = $shipment->dispute;
        $this->notifySeller($dispute, 'dispute_shipment_payment_failed', ['number' => $dispute->number], ['shipment_id' => (string) $shipment->id]);
    }

    /** Statut du paiement re-vérifié auprès du prestataire (polling mobile). */
    public function syncPayment(DisputeShipment $shipment): void
    {
        if ($shipment->isPaid() || !$shipment->payment_reference) {
            return;
        }
        try {
            if ($shipment->payment_mode === 'kpay_direct') {
                $status = strtoupper(app(MobileMoneyGateway::class)->checkPaymentStatus($shipment->payment_reference)['status'] ?? 'UNKNOWN');
                if (in_array($status, ['SUCCESS', 'SUCCESSFUL', 'COMPLETED'], true)) {
                    $this->confirmPayment($shipment);
                } elseif (in_array($status, ['FAILED', 'FAILURE', 'REJECTED', 'CANCELLED', 'CANCELED'], true)) {
                    $this->failPayment($shipment);
                }
            } elseif ($shipment->payment_mode === 'stripe_direct') {
                $status = strtolower(app(StripeService::class)->retrievePaymentIntent($shipment->payment_reference)['status'] ?? '');
                if ($status === 'succeeded') {
                    $this->confirmPayment($shipment);
                } elseif ($status === 'canceled') {
                    $this->failPayment($shipment);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('[Dispute] Vérification du paiement de la course: ' . $e->getMessage());
        }
    }

    /** Course d'un litige payée par cette référence Mobile Money (webhook), sinon null. */
    public static function shipmentForKpayReference(string $externalId): ?DisputeShipment
    {
        if (!str_starts_with($externalId, self::KPAY_PREFIX)) {
            return null;
        }

        return DisputeShipment::find((int) substr($externalId, strlen(self::KPAY_PREFIX)));
    }

    /**
     * Étape logistique enregistrée (ASSO, partenaire, ou vendeur pour l'expédition du
     * remplacement / la réception du retour). Le retour confirmé déclenche le
     * remboursement, même sans confirmation du vendeur.
     */
    public function recordStep(DisputeShipment $shipment, string $step, string $actorType, ?int $actorId, ?string $note = null, ?string $trackingNumber = null, ?UploadedFile $proof = null): DisputeShipment
    {
        if (!in_array($step, $shipment->nextSteps(), true)) {
            throw new \Exception(__('disputes.step_invalid'));
        }
        if ($actorType === 'vendor' && !in_array($step, ['shipped', 'delivered_to_vendor'], true)) {
            throw new \Exception(__('disputes.step_invalid'));
        }

        $updates = ['status' => $step];
        if ($trackingNumber) {
            $updates['carrier_tracking_number'] = trim($trackingNumber);
        }
        if ($proof) {
            $updates['proof_path'] = $proof->store('disputes/proofs', 'public');
        }
        if (in_array($step, ['delivered', 'delivered_to_vendor'], true)) {
            $updates['delivered_at'] = now();
        }
        $shipment->update($updates);

        $dispute = $shipment->dispute;
        $this->event($dispute, "shipment_{$step}", $shipment->steps()[$step], $note, $actorType, $actorId);

        if ($shipment->type === DisputeShipment::TYPE_REPLACEMENT) {
            if ($step === 'shipped') {
                $this->notifyClient($dispute, 'dispute_replacement_shipped', ['number' => $dispute->number]);
            } elseif ($step === 'delivered') {
                // Nouveau contrôle client de 48 h sur le produit de remplacement.
                $dispute->update(['auto_validate_at' => now()->addHours(Order::CONTROL_WINDOW_HOURS)]);
                $this->notifyClient($dispute, 'dispute_replacement_delivered', [
                    'number' => $dispute->number,
                    'hours' => Order::CONTROL_WINDOW_HOURS,
                ]);
            }
        } else {
            if ($step === 'pickup_scheduled') {
                $this->notifyClient($dispute, 'dispute_pickup_scheduled', ['number' => $dispute->number]);
            } elseif ($step === 'delivered_to_vendor') {
                $this->notifySeller($dispute, 'dispute_return_delivered', ['number' => $dispute->number]);
            } elseif ($step === 'confirmed') {
                $this->refund($dispute->refresh(), $actorType, $actorId);
            }
        }

        return $shipment->refresh();
    }

    // ─────────────────────────────────────────────────────────────────────
    // Remboursement et produits similaires
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Retour confirmé : le client est remboursé du prix de l'article sur son Wallet
     * ASSO. La part du vendeur, bloquée, n'est jamais versée ; la commission ASSO de
     * l'article est rendue. Idempotent.
     */
    public function refund(Dispute $dispute, string $actorType = 'system', ?int $actorId = null): float
    {
        $result = DB::transaction(function () use ($dispute, $actorType, $actorId) {
            $locked = Dispute::whereKey($dispute->id)->lockForUpdate()->with(['order', 'item'])->firstOrFail();
            if ($locked->refunded_at || $locked->status !== Dispute::STATUS_RETURN) {
                return null;
            }
            $order = $locked->order;
            $client = User::find($locked->client_id);
            $amount = round((float) $locked->item->total_price, 2);
            $held = round((float) $locked->held_amount, 2);
            $label = WalletTransaction::label('dispute_refund', ['number' => $locked->number, 'order_number' => $order->order_number]);

            if ($held > 0 && ($vendor = $this->fundsHolder($order))) {
                $this->wallet->releaseEscrow($vendor, $held, $label, 'dispute', $locked->id, ['dispute_refund' => true], 'kpay');
            }
            // Part ASSO de l'article (commission) reprise sur le compte plateforme.
            $assoPart = round($amount - $held, 2);
            if ($assoPart > 0 && ($platform = CommissionService::platformAccount())) {
                try {
                    $this->wallet->debit($platform, $assoPart, $label, 'dispute', $locked->id, ['dispute_refund' => true], 'kpay');
                } catch (\Throwable $e) {
                    Log::warning('[Dispute] Reprise de la commission ASSO impossible', ['dispute_id' => $locked->id, 'error' => $e->getMessage()]);
                }
            }
            if ($client && $amount > 0) {
                $this->wallet->credit($client, $amount, null, $label, [
                    'order_id' => $order->id,
                    'dispute_id' => $locked->id,
                    'refund' => true,
                ], 'kpay');
            }

            $locked->update([
                'held_amount' => 0,
                'refund_amount' => $amount,
                'refunded_at' => now(),
                'status' => Dispute::STATUS_REFUNDED,
                'closed_at' => now(),
            ]);
            $this->event($locked, 'refunded', __('disputes.events.refunded', [], 'fr'), null, $actorType, $actorId);

            return ['dispute' => $locked, 'amount' => $amount];
        });

        if (!$result) {
            return 0.0;
        }

        $dispute = $result['dispute'];
        $this->notifyClient($dispute, 'dispute_refunded', [
            'number' => $dispute->number,
            'amount' => number_format($result['amount'], 0, ',', ' '),
        ]);
        $this->notifySeller($dispute, 'dispute_refunded_vendor', ['number' => $dispute->number]);

        return $result['amount'];
    }

    /**
     * Après remboursement : produits similaires dont la livraison est offerte PAR LEUR
     * VENDEUR (livraison gratuite du produit ou de la boutique, à ses frais). ASSO
     * n'offre rien de plus : la gratuité suit les règles habituelles au panier.
     */
    public function similarProducts(Dispute $dispute, int $limit = 20): Collection
    {
        $product = $dispute->product ?? Product::find($dispute->product_id);
        if (!$product) {
            return collect();
        }
        $excludedShopId = $product->shop_id;

        $base = fn () => Product::query()
            ->where('status', 'active')
            ->whereHas('shop', fn ($shop) => $shop->where('status', 'active'))
            ->withEffectiveFreeDelivery()
            ->whereKeyNot($product->id)
            ->when($excludedShopId, fn ($q) => $q->where(fn ($s) => $s->whereNull('shop_id')->orWhere('shop_id', '!=', $excludedShopId)))
            ->with(['primaryImage', 'shop'])
            ->latest();

        $results = collect();
        if ($product->subcategory_id) {
            $results = $base()->where('subcategory_id', $product->subcategory_id)->limit($limit)->get();
        }
        if ($results->count() < 4 && $product->category_id) {
            $more = $base()->where('category_id', $product->category_id)->whereNotIn('id', $results->pluck('id'))
                ->limit($limit - $results->count())->get();
            $results = $results->concat($more);
        }

        return $results->values();
    }

    // ─────────────────────────────────────────────────────────────────────
    // Échéances (commande planifiée orders:auto-validate)
    // ─────────────────────────────────────────────────────────────────────

    /** Remplacement livré sans action du client pendant 48 h : validé automatiquement. */
    public function autoValidateReplacements(): int
    {
        $count = 0;
        Dispute::where('status', Dispute::STATUS_REPLACEMENT)
            ->whereNotNull('auto_validate_at')
            ->where('auto_validate_at', '<=', now())
            ->orderBy('id')
            ->each(function (Dispute $dispute) use (&$count) {
                DB::transaction(function () use ($dispute, &$count) {
                    $locked = Dispute::whereKey($dispute->id)->lockForUpdate()->first();
                    if ($locked && $locked->status === Dispute::STATUS_REPLACEMENT && $locked->auto_validate_at?->isPast()) {
                        $this->resolveReplacement($locked, 'system', null, __('disputes.events.replacement_auto_validated', [], 'fr'));
                        $count++;
                    }
                });
            });

        return $count;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Outils
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Part vendeur d'un article : prix vendeur figé sur la ligne, sinon (ancienne
     * commande) prorata de la part vendeur de la commande.
     */
    public static function itemVendorShare(Order $order, OrderItem $item): float
    {
        if ($item->seller_total_price !== null) {
            return (float) $item->seller_total_price;
        }
        $subtotal = (float) $order->subtotal;
        if ($subtotal <= 0) {
            return 0.0;
        }

        return round((float) $item->total_price * (float) $order->vendor_net_amount / $subtotal, 2);
    }

    /** Pour l'API (client, vendeur) : sans les notes internes. */
    public function toApi(Dispute $dispute, string $viewer): array
    {
        $dispute->loadMissing(['order', 'item.product.primaryImage', 'attachments', 'events', 'shipments.company']);
        $item = $dispute->item;
        $replacement = $dispute->shipment(DisputeShipment::TYPE_REPLACEMENT);
        $return = $dispute->shipment(DisputeShipment::TYPE_RETURN);

        return [
            'id' => $dispute->id,
            'number' => $dispute->number,
            'order_id' => $dispute->order_id,
            'order_number' => $dispute->order?->order_number,
            'order_item_id' => $dispute->order_item_id,
            'product' => [
                'id' => $dispute->product_id,
                'name' => $item?->product?->name,
                'image' => $item?->product?->primaryImage ? media_url($item->product->primaryImage->image_path ?? null) : null,
                'quantity' => $item?->quantity,
                'total_price' => (float) ($item?->total_price ?? 0),
                'variant_attributes' => $item?->variant_attributes,
            ],
            'reason' => $dispute->reason,
            'description' => $dispute->description,
            'status' => $dispute->status,
            'status_label' => Dispute::statusLabel($dispute->status),
            'decision' => $dispute->decision,
            'decision_note' => $dispute->decision_note,
            'resolution' => $dispute->resolution,
            'replacement_count' => $dispute->replacement_count,
            'held_amount' => $viewer === 'vendor' ? (float) $dispute->held_amount : null,
            'refund_amount' => $dispute->refund_amount !== null ? (float) $dispute->refund_amount : null,
            'replacement_control_until' => $dispute->isInReplacementControl() ? $dispute->auto_validate_at->toIso8601String() : null,
            'attachments' => $dispute->attachments->map->toApi()->values()->all(),
            'events' => $dispute->events->where('internal', false)->map->toApi()->values()->all(),
            'replacement' => $replacement?->toApi(),
            'return' => $return?->toApi(),
            'actions' => $this->actions($dispute, $viewer),
            'created_at' => $dispute->created_at?->toIso8601String(),
            'closed_at' => $dispute->closed_at?->toIso8601String(),
        ];
    }

    /** Actions possibles pour la personne qui consulte (boutons de l'app). */
    public function actions(Dispute $dispute, string $viewer): array
    {
        $replacement = $dispute->shipment(DisputeShipment::TYPE_REPLACEMENT);
        $return = $dispute->shipment(DisputeShipment::TYPE_RETURN);
        $current = $dispute->status === Dispute::STATUS_RETURN ? $return : ($dispute->status === Dispute::STATUS_REPLACEMENT ? $replacement : null);

        if ($viewer === 'client') {
            return [
                'confirm_replacement' => $dispute->isInReplacementControl(),
                'report_replacement' => $dispute->isInReplacementControl(),
                'similar_products' => $dispute->status === Dispute::STATUS_REFUNDED,
            ];
        }

        $founded = $dispute->decision === Dispute::DECISION_FOUNDED && $dispute->status === Dispute::STATUS_VENDOR_CONTACTED;

        return [
            'add_evidence' => $dispute->isOpen(),
            'replace' => $founded && $dispute->replacement_count < Dispute::MAX_REPLACEMENTS,
            'organize_return' => $founded,
            'pay_shipment' => $current && $current->payer === 'vendor' && !$current->isPaid(),
            'pending_shipment_id' => $current && !$current->isPaid() ? $current->id : null,
            'mark_shipped' => $current && $current->type === DisputeShipment::TYPE_REPLACEMENT && in_array('shipped', $current->nextSteps(), true),
            'confirm_return_received' => $current && $current->type === DisputeShipment::TYPE_RETURN && in_array('delivered_to_vendor', $current->nextSteps(), true),
        ];
    }

    private function createShipment(Dispute $dispute, string $type): DisputeShipment
    {
        // Gros : mêmes règles que le classique, mais le « vendeur » est ASSO
        // (boutique de Douala) : la course est payée depuis le back-office.
        $payer = $dispute->order?->is_wholesale ? 'asso' : 'vendor';

        return $dispute->shipments()->create([
            'type' => $type,
            'payer' => $payer,
            'payment_status' => DisputeShipment::PAYMENT_PENDING,
            'status' => 'awaiting_payment',
        ]);
    }

    private function replacementDelivered(Dispute $dispute): bool
    {
        return DisputeShipment::where('dispute_id', $dispute->id)
            ->where('type', DisputeShipment::TYPE_REPLACEMENT)
            ->where('status', 'delivered')
            ->exists();
    }

    /** À appeler dans une transaction, sur un litige verrouillé. */
    private function resolveReplacement(Dispute $dispute, string $actorType, ?int $actorId, string $label): void
    {
        $this->releaseDisputeFunds($dispute, 'replacement_conform');
        $dispute->update([
            'status' => Dispute::STATUS_RESOLVED,
            'auto_validate_at' => null,
            'closed_at' => now(),
        ]);
        $this->event($dispute, 'resolved', $label, null, $actorType, $actorId);
        DB::afterCommit(fn () => $this->notifySeller($dispute, 'dispute_resolved_vendor', ['number' => $dispute->number]));
    }

    /** Part de l'article rendue disponible au vendeur (à appeler dans une transaction). */
    private function releaseDisputeFunds(Dispute $dispute, string $reason): void
    {
        $held = round((float) $dispute->held_amount, 2);
        if ($held > 0 && ($vendor = $this->fundsHolder($dispute->order))) {
            $this->wallet->unlockFunds(
                $vendor,
                $held,
                WalletTransaction::label('dispute_funds_released', ['number' => $dispute->number]),
                'dispute',
                $dispute->id,
                ['reason' => $reason],
                'kpay'
            );
        }
        $dispute->update(['held_amount' => 0]);
    }

    private function fundsHolder(?Order $order): ?User
    {
        return $order?->vendor_funds_holder_id ? User::find($order->vendor_funds_holder_id) : null;
    }

    private function quoteItems(Dispute $dispute): array
    {
        $item = $dispute->item;

        return [[
            'product_id' => $item->product_id,
            'quantity' => (int) $item->quantity,
            'price_tier_id' => $item->price_tier_id,
            'product_variant_id' => $item->product_variant_id,
        ]];
    }

    private function assertNotDecided(Dispute $dispute): void
    {
        if ($dispute->decision !== null || !$dispute->isOpen()) {
            throw new \Exception(__('disputes.already_decided'));
        }
    }

    /** Suffixe des libellés du portefeuille : return ou replacement. */
    private function shipmentKind(DisputeShipment $shipment): string
    {
        return $shipment->type === DisputeShipment::TYPE_RETURN ? 'return' : 'replacement';
    }

    /** @param UploadedFile[] $files */
    private function storePhotos(array $files): array
    {
        return collect($files)
            ->filter(fn ($f) => $f instanceof UploadedFile)
            ->map(fn (UploadedFile $f) => $f->store('disputes', 'public'))
            ->values()
            ->all();
    }

    private function event(Dispute $dispute, string $type, string $label, ?string $note, string $actorType, ?int $actorId, bool $internal = false): void
    {
        DisputeEvent::create([
            'dispute_id' => $dispute->id,
            'type' => $type,
            'label' => $label,
            'note' => $note,
            'internal' => $internal,
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'occurred_at' => now(),
        ]);
    }

    private function notifyClient(Dispute $dispute, string $type, array $replace): void
    {
        $this->notify(User::find($dispute->client_id), $dispute, $type, $replace);
    }

    private function notifySeller(Dispute $dispute, string $type, array $replace, array $data = []): void
    {
        // Import en gros : le « vendeur » est ASSO, qui suit le dossier depuis le back-office.
        if ($dispute->order?->is_wholesale) {
            return;
        }
        $this->notify($dispute->seller_id ? User::find($dispute->seller_id) : null, $dispute, $type, $replace, $data + ['role' => 'vendor']);
    }

    private function notify(?User $user, Dispute $dispute, string $type, array $replace, array $data = []): void
    {
        if (!$user) {
            return;
        }
        try {
            $this->fcm->sendToUser(
                $user,
                $user->localized("notifications.{$type}.title", $replace),
                $user->localized("notifications.{$type}.body", $replace),
                $data + [
                    'type' => $type,
                    'dispute_id' => (string) $dispute->id,
                    'order_id' => (string) $dispute->order_id,
                ]
            );
        } catch (\Throwable $e) {
            Log::warning("[Dispute] FCM {$type} échec: " . $e->getMessage());
        }
    }
}
