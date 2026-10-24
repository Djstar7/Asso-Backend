<?php

namespace App\Services;

use App\Models\Dispute;
use App\Models\DisputeAdvance;
use App\Models\DisputeAttachment;
use App\Models\DisputeEvent;
use App\Models\DisputeReceipt;
use App\Models\DisputeResolutionTask;
use App\Models\DisputeShipment;
use App\Models\Order;
use App\Models\OrderDeliveryClaim;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/** Arbitrages sur preuves et suivi durable des attentes, sans simuler des événements transporteur. */
class DisputeResolutionService
{
    public function __construct(private DisputeService $disputes, private WalletService $wallet) {}

    public function hours(string $key): int
    {
        return max(1, (int) Setting::get('dispute_'.$key, config('disputes.'.$key, 48)));
    }

    public function assertAdmin(User $actor): void
    {
        abort_unless($actor->isAdmin() && ! $actor->isTechnicalAccount(), 403);
    }

    private function evidence(Dispute $dispute, int $id): DisputeAttachment
    {
        // Une pièce client/vendeur doit être examinée par ASSO ; sa présence seule ne valide rien.
        return $dispute->attachments()->whereKey($id)->firstOrFail();
    }

    private function audit(Dispute $dispute, string $type, string $note, ?User $actor = null, string $actorType = 'admin'): void
    {
        DisputeEvent::create(['dispute_id' => $dispute->id, 'type' => $type,
            'label' => match ($type) {
                'independent_receipt' => 'Preuve de réception vérifiée par ASSO',
                'receipt_contested' => 'Réception contestée : arbitrage ASSO requis',
                'receipt_accepted' => 'Réception établie sur preuve indépendante',
                'administrative_refund' => 'Remboursement autorisé par arbitrage ASSO',
                'funding_advance' => 'Transport avancé par ASSO',
                default => 'Échéance dépassée : dossier réaffecté',
            }, 'note' => $note, 'actor_type' => $actor ? $actorType : 'system', 'actor_id' => $actor?->id,
            'internal' => false, 'occurred_at' => now()]);
    }

    public function registerReceipt(DisputeShipment $shipment, User $actor, int $attachmentId, string $location, string $note): DisputeReceipt
    {
        $this->assertAdmin($actor);

        return DB::transaction(function () use ($shipment, $actor, $attachmentId, $location, $note) {
            $dispute = Dispute::whereKey($shipment->dispute_id)->lockForUpdate()->firstOrFail();
            $shipment = DisputeShipment::whereKey($shipment->id)->lockForUpdate()->firstOrFail();
            if (! $dispute->isOpen() || $dispute->refunded_at || ! $shipment->isPaid()
                || $shipment->type === DisputeShipment::TYPE_REPLACEMENT || $shipment->status === 'confirmed'
                || ! in_array($dispute->status, [Dispute::STATUS_REPLACEMENT, Dispute::STATUS_RETURN], true)) {
                throw new \DomainException('Cette course ne peut pas recevoir de preuve de retour.');
            }
            $this->evidence($dispute, $attachmentId);
            if (DisputeReceipt::where('dispute_shipment_id', $shipment->id)->exists()) {
                throw new \DomainException('Une preuve de réception est déjà en cours de traitement.');
            }
            $receipt = DisputeReceipt::create(['dispute_shipment_id' => $shipment->id, 'verified_by' => $actor->id,
                'dispute_attachment_id' => $attachmentId, 'location' => $location, 'note' => $note,
                'contest_until' => now()->addHours($this->hours('receipt_contest_hours'))]);
            $this->audit($dispute, 'independent_receipt', "Pièce #{$attachmentId} — {$location} — {$note}", $actor);
            DB::afterCommit(fn () => $this->notify($dispute->seller, 'Réception vérifiée par ASSO', 'Une preuve de réception est disponible. Vous pouvez contester avec un justificatif avant le '.$receipt->contest_until->toIso8601String(), ['dispute_id' => (string) $dispute->id, 'role' => 'vendor']));

            return $receipt;
        });
    }

    public function contestReceipt(DisputeReceipt $receipt, User $seller, int $attachmentId, string $note): void
    {
        DB::transaction(function () use ($receipt, $seller, $attachmentId, $note) {
            $dispute = Dispute::whereKey($receipt->shipment->dispute_id)->lockForUpdate()->firstOrFail();
            $receipt = DisputeReceipt::whereKey($receipt->id)->lockForUpdate()->firstOrFail();
            abort_unless($dispute->seller_id === $seller->id, 403);
            if (! $dispute->isOpen() || $dispute->refunded_at || $receipt->accepted_at || $receipt->contested_at || $receipt->contest_until->isPast()) {
                throw new \DomainException('Le délai de contestation est terminé ou une contestation est déjà enregistrée.');
            }
            $attachment = $this->evidence($dispute, $attachmentId);
            abort_unless($attachment->author_type === 'vendor' && $attachment->author_id === $seller->id, 403);
            $receipt->update(['contest_note' => $note, 'contest_attachment_id' => $attachmentId, 'contested_at' => now()]);
            $this->audit($dispute, 'receipt_contested', "Pièce #{$attachmentId} — {$note}", $seller, 'vendor');
            DB::afterCommit(fn () => $this->alertAdmins('Réception contestée', 'Litige '.$dispute->number.' : arbitrage sur justificatifs requis.'));
        });
    }

    public function acceptReceipt(DisputeReceipt $receipt, ?User $actor = null, ?string $note = null): void
    {
        if ($actor) {
            $this->assertAdmin($actor);
        }
        DB::transaction(function () use ($receipt, $actor, $note) {
            $dispute = Dispute::whereKey($receipt->shipment->dispute_id)->lockForUpdate()->firstOrFail();
            $receipt = DisputeReceipt::whereKey($receipt->id)->lockForUpdate()->firstOrFail();
            if ($receipt->accepted_at || ! $dispute->isOpen() || $dispute->refunded_at) {
                return;
            }
            if (! $actor && ($receipt->contested_at || $receipt->contest_until->isFuture())) {
                return;
            }
            if ($actor && mb_strlen(trim($note ?? '')) < 10) {
                throw new \DomainException('Motivez l’arbitrage de réception.');
            }
            $shipment = DisputeShipment::whereKey($receipt->dispute_shipment_id)->lockForUpdate()->firstOrFail();
            if (! $shipment->isPaid() || $shipment->type === DisputeShipment::TYPE_REPLACEMENT) {
                throw new \DomainException('Retour invalide.');
            }
            if ($shipment->status !== 'confirmed') {
                // La preuve vérifiée remplace le code, sans prétendre à une synchronisation transporteur.
                $shipment->update(['status' => 'delivered_to_vendor', 'delivered_at' => now()]);
                $this->disputes->recordStep($shipment->fresh(), 'confirmed', 'system', null, 'Réception sur justificatif ASSO #'.$receipt->id);
            }
            $receipt->update(['accepted_at' => now()]);
            $this->audit($dispute, 'receipt_accepted', $note ?? 'Délai contradictoire expiré sans contestation étayée.', $actor);
        });
    }

    /** Décision explicite, sans dépendance à la réception, au fournisseur ni à un remboursement de transport. */
    public function refund(Dispute $dispute, User $actor, int $attachmentId, string $note): float
    {
        $this->assertAdmin($actor);
        if (mb_strlen(trim($note)) < 10) {
            throw new \DomainException('Une décision motivée est obligatoire.');
        }

        return DB::transaction(function () use ($dispute, $actor, $attachmentId, $note) {
            $dispute = Dispute::whereKey($dispute->id)->lockForUpdate()->firstOrFail();
            if ($dispute->refunded_at) {
                return 0.0;
            }
            if (! $dispute->isOpen()) {
                throw new \DomainException('Le dossier est déjà clos.');
            }
            $this->evidence($dispute, $attachmentId);
            $dispute->update(['decision' => Dispute::DECISION_FOUNDED, 'decision_note' => $note,
                'decided_by' => $actor->id, 'decided_at' => now(), 'receipt_waived_at' => now(),
                'status' => Dispute::STATUS_RETURN, 'resolution' => Dispute::RESOLUTION_RETURN_REFUND,
                'auto_validate_at' => null, 'replacement_due_at' => null]);
            $this->audit($dispute, 'administrative_refund', "Pièce #{$attachmentId} — {$note}", $actor);
            $amount = $this->disputes->refund($dispute, 'admin', $actor->id);
            // Les courses déjà financées restent dans l'historique pour le recours financier.
            $dispute->refresh()->update(['status' => Dispute::STATUS_REFUNDED, 'closed_at' => now()]);
            DisputeResolutionTask::where('dispute_id', $dispute->id)->whereNull('completed_at')->update(['completed_at' => now()]);

            return $amount;
        });
    }

    public function advanceTransport(DisputeShipment $shipment, User $actor, string $note): void
    {
        $this->assertAdmin($actor);
        DB::transaction(function () use ($shipment, $actor, $note) {
            $dispute = Dispute::whereKey($shipment->dispute_id)->lockForUpdate()->firstOrFail();
            $shipment = DisputeShipment::whereKey($shipment->id)->lockForUpdate()->firstOrFail();
            if ($shipment->isPaid()) {
                return;
            }
            if (! $dispute->isOpen() || $dispute->refunded_at || $shipment->status === 'awaiting_preparation') {
                throw new \DomainException('Financement indisponible pour cette étape.');
            }
            // Une tentative externe doit être réconciliée avant de changer le financeur.
            if ($shipment->payment_reference && $shipment->payment_status !== DisputeShipment::PAYMENT_FAILED) {
                throw new \DomainException('Un paiement externe est en cours : réconciliez-le avant l’avance.');
            }
            if (! $shipment->deliverer_company_id || (float) $shipment->carrier_amount <= 0) {
                throw new \DomainException('Choisissez d’abord un partenaire et un devis valide.');
            }
            $vendorFunded = $shipment->payer === 'vendor';
            $shipment->update(['payer' => 'asso', 'payment_reference' => null]);
            $this->disputes->payByAsso($shipment, $actor);
            if ($vendorFunded) {
                DisputeAdvance::firstOrCreate(['dispute_shipment_id' => $shipment->id], [
                    'debtor_id' => $dispute->seller_id, 'authorized_by' => $actor->id,
                    'amount' => $shipment->carrier_amount, 'note' => $note]);
            }
            $this->audit($dispute, 'funding_advance', $note, $actor);
        });
    }

    /** Pré-livraison : un dossier par commande, montants encaissés conservés sur leurs rails. */
    public function openDeliveryClaim(Order $order, User $client, string $reason, string $description, array $proofs = []): OrderDeliveryClaim
    {
        return DB::transaction(function () use ($order, $client, $reason, $description, $proofs) {
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            abort_unless($order->user_id === $client->id, 403);
            if (! in_array($reason, OrderDeliveryClaim::REASONS, true) || in_array($order->status, ['delivered', 'cancelled'], true)
                || $order->refunded_at || array_sum($order->collectedAmounts()) <= 0) {
                throw new \DomainException('Cette commande ne permet pas une réclamation avant livraison.');
            }

            return OrderDeliveryClaim::firstOrCreate(['order_id' => $order->id], ['reason' => $reason, 'description' => $description, 'client_proofs' => $proofs]);
        });
    }

    public function resolveDeliveryClaim(OrderDeliveryClaim $claim, User $actor, string $decision, string $note, string $proofPath): float
    {
        $this->assertAdmin($actor);

        return DB::transaction(function () use ($claim, $actor, $decision, $note, $proofPath) {
            $order = Order::whereKey($claim->order_id)->lockForUpdate()->firstOrFail();
            $claim = OrderDeliveryClaim::whereKey($claim->id)->lockForUpdate()->firstOrFail();
            if ($claim->status !== 'open') {
                return 0.0;
            }
            if (! in_array($decision, ['refund', 'reject'], true) || mb_strlen(trim($note)) < 10 || blank($proofPath)) {
                throw new \DomainException('Décision, motif et justificatif obligatoires.');
            }
            $amount = 0.0;
            if ($decision === 'refund') {
                if ($order->status === 'delivered' || $order->disputes()->exists() || $order->refunded_at) {
                    throw new \DomainException('Commande livrée ou déjà traitée : utilisez le parcours après livraison.');
                }
                if ($order->settled_at) {
                    if ($order->vendor_funds_status !== Order::VENDOR_FUNDS_HELD || $order->vendor_funds_released_at) {
                        throw new \DomainException('Les fonds ont déjà été libérés : régularisation financière nécessaire.');
                    }
                    $amount = round(array_sum($order->collectedAmounts()), 2);
                    $held = (float) $order->vendor_funds_held_amount;
                    if ($held > $amount) {
                        throw new \DomainException('Montants incohérents.');
                    }
                    $label = 'Remboursement avant livraison #'.$order->order_number;
                    if ($held > 0) {
                        $holder = User::findOrFail($order->vendor_funds_holder_id);
                        $this->wallet->releaseEscrow($holder, $held, $label, 'order', $order->id, ['delivery_claim_id' => $claim->id], 'kpay');
                    }
                    // Les frais déjà réglés au transporteur sont avancés par ASSO ; le recours est indépendant.
                    $advance = round($amount - $held, 2);
                    if ($advance > 0) {
                        $platform = CommissionService::platformAccount() ?? throw new \DomainException('Compte ASSO introuvable.');
                        $this->wallet->debit($platform, $advance, $label, 'order', $order->id, ['delivery_claim_id' => $claim->id], 'kpay');
                        DisputeAdvance::create(['order_delivery_claim_id' => $claim->id, 'amount' => $advance,
                            'authorized_by' => $actor->id, 'note' => 'Responsabilité à établir : '.$note]);
                    }
                    $this->wallet->credit($order->user, $amount, null, $label, ['order_id' => $order->id, 'delivery_claim_id' => $claim->id, 'refund' => true], 'kpay');
                    $order->update(['vendor_funds_held_amount' => 0, 'vendor_funds_status' => Order::VENDOR_FUNDS_RELEASED,
                        'payment_status' => Order::PAYMENT_REFUNDED, 'refunded_at' => now(), 'auto_validate_at' => null]);
                } else {
                    $amount = app(OrderService::class)->refundBuyer($order, 'Remboursement avant livraison #'.$order->order_number,
                        ['delivery_claim_id' => $claim->id]);
                }
                if ($amount <= 0) {
                    throw new \DomainException('Aucun montant encaissé à rembourser.');
                }
                $restoreStock = $order->status === 'pending' && ! $order->shipped_at && ! $order->settled_at;
                $order->refresh()->update(['status' => 'cancelled', 'cancelled_at' => now(), 'cancel_reason' => $note]);
                // Le stock en transit/perdu ne redevient pas vendable automatiquement.
                if ($restoreStock) {
                    foreach ($order->items as $item) {
                        $item->restoreStock();
                    }
                }
                app(OrderTrackingService::class)->record($order, 'cancelled', null, $note, 'admin', $actor->id);
            }
            $claim->update(['status' => $decision === 'refund' ? 'refunded' : 'rejected', 'decision_note' => $note,
                'decided_by' => $actor->id, 'proof_path' => $proofPath, 'refund_amount' => $amount, 'resolved_at' => now()]);
            DisputeResolutionTask::where('order_delivery_claim_id', $claim->id)->whereNull('completed_at')->update(['completed_at' => now()]);

            return $amount;
        });
    }

    private function task(string $key, array $attributes, $since): DisputeResolutionTask
    {
        $hours = $this->hours($attributes['stage']);

        $assignee = $attributes['responsible'] === 'asso'
            ? DisputeService::disputeStaff()->first(fn ($user) => $user->isAdmin())?->id : null;

        return DisputeResolutionTask::firstOrCreate(['workflow_key' => $key], $attributes + [
            'assigned_to' => $assignee, 'due_at' => $since->copy()->addHours($hours),
        ]);
    }

    /** Une panne sur un dossier ne bloque pas le traitement des suivants. */
    public function process(): void
    {
        DisputeShipment::where('payer', 'asso')->where('payment_status', '!=', DisputeShipment::PAYMENT_PAID)
            ->whereNotNull('funding_error')->where('status', '!=', 'awaiting_preparation')
            ->whereHas('dispute', fn ($q) => $q->open()->whereNull('refunded_at'))
            ->eachById(fn ($shipment) => $this->disputes->autoFundAsso($shipment));
        DisputeReceipt::whereNull('accepted_at')->whereNull('contested_at')->where('contest_until', '<=', now())
            ->whereHas('shipment.dispute', fn ($q) => $q->open()->whereNull('refunded_at'))
            ->eachById(function ($receipt) {
                try {
                    $this->acceptReceipt($receipt);
                } catch (\Throwable $e) {
                    Log::warning('Réception indépendante en attente', ['receipt_id' => $receipt->id, 'error' => $e->getMessage()]);
                }
            });
        Dispute::open()->eachById(function (Dispute $dispute) {
            try {
                $this->trackDispute($dispute);
            } catch (\Throwable $e) {
                Log::error('Suivi du litige échoué', ['dispute_id' => $dispute->id, 'error' => $e->getMessage()]);
            }
        });
        // Détection proactive des commandes encaissées non livrées, y compris les acomptes.
        Order::whereNotIn('status', ['delivered', 'cancelled'])->whereNull('refunded_at')
            ->where(fn ($q) => $q->where('payment_status', Order::PAYMENT_PAID)->orWhere('balance_status', Order::BALANCE_PAID))
            ->eachById(function (Order $order) {
                try {
                    if (array_sum($order->collectedAmounts()) <= 0) {
                        return;
                    }
                    $transit = $order->shipped_at !== null || $order->status === 'shipped';
                    $stage = $transit ? ($order->isInternationalExport() || $order->is_wholesale ? 'international_transit_hours' : 'local_transit_hours')
                        : ($order->is_wholesale ? 'import_preparation_hours' : 'preparation_hours');
                    $since = $order->shipped_at ?? $order->confirmed_at ?? $order->created_at;
                    if ($since->copy()->addHours($this->hours($stage))->isFuture()) {
                        return;
                    }
                    $claim = OrderDeliveryClaim::firstOrCreate(['order_id' => $order->id], ['reason' => 'overdue',
                        'description' => 'Échéance de préparation ou de livraison dépassée : enquête ASSO requise.']);
                    $this->trackClaim($claim);
                } catch (\Throwable $e) {
                    Log::error('Suivi avant livraison échoué', ['order_id' => $order->id, 'error' => $e->getMessage()]);
                }
            });
        OrderDeliveryClaim::where('status', 'open')->eachById(fn ($claim) => $this->trackClaim($claim));
        DisputeResolutionTask::whereNull('completed_at')->where('due_at', '<=', now())->eachById(function ($task) {
            try {
                $this->expire($task);
            } catch (\Throwable $e) {
                $task->update(['last_error' => $e->getMessage()]);
                Log::warning('Échéance de résolution en échec', ['task_id' => $task->id, 'error' => $e->getMessage()]);
            }
        });
    }

    private function trackClaim(OrderDeliveryClaim $claim): void
    {
        if ($claim->status !== 'open') {
            return;
        }
        $this->task('claim:'.$claim->id, ['order_delivery_claim_id' => $claim->id, 'stage' => 'review_hours',
            'responsible' => 'asso', 'expected_proof' => 'Preuve de préparation, prise en charge, livraison, ou décision motivée.'], $claim->created_at);
    }

    private function trackDispute(Dispute $dispute): void
    {
        if ($dispute->refunded_at || $dispute->isInReplacementControl()) {
            DisputeResolutionTask::where('dispute_id', $dispute->id)->whereNull('completed_at')->update(['completed_at' => now()]);

            return;
        }
        $receipt = DisputeReceipt::whereHas('shipment', fn ($q) => $q->where('dispute_id', $dispute->id))->whereNull('accepted_at')->first();
        $shipment = $dispute->shipments()->whereNotIn('status', ['confirmed', 'delivered'])->orderByDesc('id')->first();
        if ($receipt) {
            $key = 'receipt:'.$receipt->id.($receipt->contested_at ? ':contested' : ':waiting');
            $stage = $receipt->contested_at ? 'review_hours' : 'receipt_contest_hours';
            $since = $receipt->contested_at ?? $receipt->created_at;
            $responsible = $receipt->contested_at ? 'asso' : 'vendor';
        } elseif (! $dispute->decision || ! $shipment) {
            $key = 'dispute:'.$dispute->id.':'.$dispute->status;
            $stage = 'review_hours';
            $since = $dispute->created_at;
            $responsible = 'asso';
        } else {
            $key = 'shipment:'.$shipment->id.':'.$shipment->status;
            $stage = ! $shipment->isPaid() ? ($shipment->status === 'awaiting_preparation' ? 'preparation_hours' : 'payment_hours')
                : (in_array($shipment->status, ['courier_selected', 'pickup_scheduled', 'solex_pickup_scheduled'], true)
                    ? 'dispatch_hours' : ($shipment->isInternational() ? 'international_transit_hours' : 'local_transit_hours'));
            $since = $shipment->status === 'awaiting_payment' ? $shipment->created_at : $shipment->updated_at;
            $responsible = ! $shipment->isPaid() ? $shipment->payer : 'carrier';
        }
        $task = $this->task($key, ['dispute_id' => $dispute->id, 'stage' => $stage, 'responsible' => $responsible,
            'expected_proof' => match ($stage) {
                'payment_hours' => 'Paiement du transport confirmé, avec référence et financeur identifiés.',
                'preparation_hours' => 'Produit de remplacement disponible et prêt à être collecté.',
                'receipt_contest_hours' => 'Preuve indépendante de réception, identification du produit et éventuelle contestation étayée.',
                'dispatch_hours' => 'Preuve de prise en charge effective par le transporteur.',
                'international_transit_hours', 'local_transit_hours' => 'Événement transport vérifié ou résultat d’enquête motivé sur le colis.',
                default => 'Décision ASSO motivée sur les justificatifs contradictoires disponibles.',
            }], $since);
        DisputeResolutionTask::where('dispute_id', $dispute->id)->whereKeyNot($task->id)->whereNull('completed_at')->update(['completed_at' => now()]);
    }

    private function expire(DisputeResolutionTask $task): void
    {
        DB::transaction(function () use ($task) {
            $task = DisputeResolutionTask::whereKey($task->id)->lockForUpdate()->firstOrFail();
            if ($task->completed_at || $task->due_at->isFuture()) {
                return;
            }
            $dispute = $task->dispute;
            if ($dispute && (! $dispute->isOpen() || $dispute->refunded_at)
                || $task->claim && $task->claim->status !== 'open') {
                $task->update(['completed_at' => now()]);

                return;
            }
            $admins = DisputeService::disputeStaff()->filter(fn ($u) => $u->isAdmin())->sortBy('id')->values();
            $next = $admins->first(fn ($u) => $u->id !== $task->assigned_to) ?? $admins->first();
            $task->update(['assigned_to' => $next?->id, 'escalations' => $task->escalations + 1,
                'escalated_at' => now(), 'due_at' => now()->addHours($this->hours('review_hours')),
                'responsible' => 'asso', 'last_error' => $next ? null : 'Aucun administrateur opérationnel disponible.']);
            if ($dispute) {
                $this->audit($dispute, 'resolution_escalation', 'Étape '.$task->stageLabel().' — responsable #'.($next?->id ?? 'non affecté'));
            }
            DB::afterCommit(fn () => $this->alertAdmins('Échéance de résolution dépassée', 'Tâche #'.$task->id.' — '.$task->stageLabel().' — responsable #'.($next?->id ?? 'non affecté').'. Consultez les déblocages ASSO.'));
            // La reprise de financement reste bornée par l’enveloppe configurée et un devis existant.
            if ($dispute && $task->stage === 'payment_hours') {
                $shipment = $dispute->shipments()->where('status', 'awaiting_payment')->latest('id')->first();
                if ($shipment && ! $shipment->isPaid()) {
                    if ($shipment->payer === 'asso') {
                        DB::afterCommit(fn () => $this->disputes->autoFundAsso($shipment));
                    } elseif ($next && (float) $shipment->carrier_amount > 0
                        && (float) $shipment->carrier_amount <= (float) Setting::get('dispute_automatic_advance_limit', config('disputes.automatic_advance_limit'))) {
                        DB::afterCommit(function () use ($shipment, $next, $task) {
                            try {
                                $this->advanceTransport($shipment, $next, 'Avance automatique autorisée par enveloppe, échéance #'.$task->id);
                            } catch (\Throwable $e) {
                                $task->update(['last_error' => $e->getMessage()]);
                            }
                        });
                    }
                }
            }
        });
    }

    private function alertAdmins(string $title, string $message): void
    {
        foreach (DisputeService::disputeStaff()->filter(fn ($u) => $u->isAdmin()) as $admin) {
            $this->notify($admin, $title, $message, ['role' => 'staff']);
        }
    }

    private function notify(?User $user, string $title, string $message, array $data = []): void
    {
        if (! $user || $user->isTechnicalAccount()) {
            return;
        }
        try {
            app(FirebaseMessagingService::class)->sendToUser($user, $title, $message,
                $data + ['type' => 'dispute_resolution']);
        } catch (\Throwable $e) {
            Log::warning('Notification de résolution échouée', ['user_id' => $user->id, 'error' => $e->getMessage()]);
        }
        if ($user->email) {
            try {
                \Illuminate\Support\Facades\Mail::raw($message, fn ($mail) => $mail->to($user->email)->subject($title));
            } catch (\Throwable $e) {
                Log::warning('Courriel de résolution échoué', ['user_id' => $user->id, 'error' => $e->getMessage()]);
            }
        }
    }
}
