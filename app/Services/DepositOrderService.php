<?php

namespace App\Services;

use App\Models\DelivererCompany;
use App\Models\Order;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Commande avec acompte (produits sur commande / importés).
 *
 * Parcours : l'acheteur paie l'acompte (+ la livraison) à la commande → le vendeur
 * valide et expédie (il n'est PAS réglé à ce stade) → à la présentation du produit
 * (livraison ou retrait), un employé ASSO et le client vérifient ensemble la
 * marchandise → l'employé valide → le solde est débloqué et l'acheteur le paie dans
 * l'app → la commande est réglée (vendeur, livreur, ASSO) et la remise finale (code à
 * 6 chiffres ou confirmation de retrait) devient possible.
 *
 * En cas de problème ou de renoncement, l'employé clôture la commande en répartissant
 * l'acompte au cas par cas (client, vendeur, livreur, reste à ASSO).
 */
class DepositOrderService
{
    /** Étapes de suivi qui présentent le produit au client : la vérification peut commencer. */
    public const PRESENTATION_STEPS = ['out_for_delivery', 'arrived', 'arrived_hub', 'ready_for_pickup'];

    private const BALANCE_SUFFIX = '-SOLDE';

    public function __construct(
        private OrderService $orders,
        private WalletService $wallet,
        private FirebaseMessagingService $fcm,
    ) {
    }

    /** Règles de validation de l'option produit (vendeur, admin). */
    public static function productRules(string $presence = 'sometimes'): array
    {
        return [
            'deposit_enabled' => "{$presence}|boolean",
            'deposit_rate' => 'required_if_accepted:deposit_enabled|nullable|numeric|min:1|max:99',
        ];
    }

    /** Champs produit à enregistrer depuis une requête validée (absents = inchangés). */
    public static function productAttributes(array $validated): array
    {
        $attributes = [];
        if (array_key_exists('deposit_enabled', $validated)) {
            $attributes['deposit_enabled'] = (bool) $validated['deposit_enabled'];
        }
        if (array_key_exists('deposit_rate', $validated) && $validated['deposit_rate'] !== null) {
            $attributes['deposit_rate'] = round((float) $validated['deposit_rate'], 2);
        }

        return $attributes;
    }

    /** Option « commande avec acompte » d'un produit pour l'API (fiche, listes). */
    public static function productInfo(\App\Models\Product $product): array
    {
        return [
            'deposit_enabled' => $product->requiresDeposit(),
            'deposit_rate' => $product->requiresDeposit() ? (float) $product->deposit_rate : null,
        ];
    }

    /** Acompte d'une ligne : % du prix acheteur, arrondi au franc. */
    public static function depositFor(float $buyerTotal, float $rate): float
    {
        $rate = max(0.0, min(100.0, $rate));

        return round($buyerTotal * $rate / 100);
    }

    /** Référence KPay du paiement du solde (distincte de celle de l'acompte). */
    public static function balanceReference(Order $order): string
    {
        return $order->order_number . self::BALANCE_SUFFIX;
    }

    /** Commande dont le solde est payé par cette référence KPay, sinon null. */
    public static function orderForBalanceReference(string $externalId): ?Order
    {
        if (!str_ends_with($externalId, self::BALANCE_SUFFIX)) {
            return null;
        }

        return Order::where('order_number', substr($externalId, 0, -strlen(self::BALANCE_SUFFIX)))
            ->where('payment_plan', Order::PLAN_DEPOSIT)
            ->first();
    }

    /**
     * Produit présenté au client (en route, arrivé, disponible au retrait) : la
     * commande apparaît « À contacter » dans le back-office. Appelé par OrderTrackingService.
     */
    public function onTrackingStep(Order $order, string $step): void
    {
        if (!$order->isDepositOrder()
            || !in_array($step, self::PRESENTATION_STEPS, true)
            || $order->verification_status !== Order::VERIFICATION_PENDING) {
            return;
        }

        $order->forceFill(['verification_status' => Order::VERIFICATION_TO_CONTACT])->saveQuietly();
    }

    /** L'employé a joint le client (appel, message) : vérification en cours. */
    public function recordContact(Order $order, User $employee, ?string $note = null): Order
    {
        return $this->transition($order, [Order::VERIFICATION_PENDING, Order::VERIFICATION_TO_CONTACT, Order::VERIFICATION_ISSUE], function (Order $locked) use ($employee, $note) {
            $locked->update([
                'verification_status' => Order::VERIFICATION_CONTACTED,
                'verification_note' => $note ?? $locked->verification_note,
            ]);
            $this->trace($locked, 'Client contacté par ASSO', $note, $employee);
        });
    }

    /**
     * Vérification conjointe réussie : l'employé valide, le solde est débloqué et
     * l'acheteur est prévenu immédiatement.
     */
    public function validateVerification(Order $order, User $employee, ?string $note = null): Order
    {
        $order = $this->transition($order, [Order::VERIFICATION_TO_CONTACT, Order::VERIFICATION_CONTACTED, Order::VERIFICATION_ISSUE], function (Order $locked) use ($employee, $note) {
            if ($locked->status === 'cancelled') {
                throw new \Exception(__('orders.already_processed'));
            }
            $locked->update([
                'verification_status' => Order::VERIFICATION_VERIFIED,
                'verified_by' => $employee->id,
                'verified_at' => now(),
                'verification_note' => $note ?? $locked->verification_note,
                'balance_status' => Order::BALANCE_UNLOCKED,
                'balance_unlocked_at' => now(),
            ]);
            $this->trace($locked, 'Vérification ASSO validée — solde débloqué', $note, $employee);
        });

        $this->notifyBuyer($order, 'order_balance_due', [
            'order_number' => $order->order_number,
            'amount' => number_format((float) $order->balance_amount, 0, ',', ' '),
        ]);

        return $order;
    }

    /** Problème constaté pendant la vérification : le solde reste bloqué. */
    public function reportIssue(Order $order, User $employee, string $note): Order
    {
        return $this->transition($order, [Order::VERIFICATION_PENDING, Order::VERIFICATION_TO_CONTACT, Order::VERIFICATION_CONTACTED], function (Order $locked) use ($employee, $note) {
            $locked->update([
                'verification_status' => Order::VERIFICATION_ISSUE,
                'verified_by' => $employee->id,
                'verified_at' => now(),
                'verification_note' => $note,
            ]);
            $this->trace($locked, 'Vérification ASSO : problème signalé', $note, $employee);
        });
    }

    /**
     * Lance le paiement du solde par l'acheteur. Wallet : prélevé (bloqué) et confirmé
     * aussitôt ; Mobile Money / carte : confirmé par le webhook ou le polling.
     *
     * @param string $paymentMode wallet | kpay_direct | stripe_direct
     */
    public function initiateBalancePayment(Order $order, string $paymentMode, ?string $provider = null, ?string $phone = null): Order
    {
        $order = DB::transaction(function () use ($order, $paymentMode, $provider, $phone) {
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if (!$locked->isDepositOrder() || $locked->status === 'cancelled') {
                throw new \Exception(__('orders.balance_not_payable'));
            }
            if ($locked->balance_status === Order::BALANCE_PAID) {
                throw new \Exception(__('orders.balance_already_paid'));
            }
            if ($locked->balance_status !== Order::BALANCE_UNLOCKED) {
                throw new \Exception(__('orders.balance_locked_until_verification'));
            }

            $amount = (float) $locked->balance_amount;

            if ($paymentMode === 'wallet') {
                $this->wallet->lockFunds(
                    $locked->user,
                    $amount,
                    "Solde commande #{$locked->order_number}",
                    'order',
                    $locked->id,
                    ['balance' => true],
                    'kpay'
                );
                $locked->update(['balance_payment_method' => 'wallet_kpay']);

                return $locked;
            }

            $locked->update([
                'balance_payment_method' => $paymentMode,
                'balance_payment_reference' => null,
            ]);
            $this->orders->initiateDirectPayment($locked, $amount, $paymentMode, $provider, $phone, balance: true);

            return $locked;
        });

        if ($paymentMode === 'wallet') {
            $this->confirmBalancePayment($order);
            $order->refresh();
        }

        return $order;
    }

    /**
     * Solde encaissé (idempotent) : la commande est réglée (vendeur, livreur, ASSO) et
     * la remise finale devient possible.
     */
    public function confirmBalancePayment(Order $order): void
    {
        $settled = DB::transaction(function () use ($order) {
            $locked = Order::whereKey($order->id)->lockForUpdate()->with('items')->first();
            if (!$locked || $locked->balance_status !== Order::BALANCE_UNLOCKED || !$locked->balance_payment_method) {
                return null; // déjà traité, ou aucun paiement lancé
            }

            $locked->update([
                'balance_status' => Order::BALANCE_PAID,
                'balance_paid_at' => now(),
            ]);

            // Rail direct : trace dans l'historique de l'acheteur (solde Wallet inchangé).
            if (!str_starts_with((string) $locked->balance_payment_method, 'wallet_')) {
                $buyerBalance = (float) (User::find($locked->user_id)?->kpayBalanceFor('XAF') ?? 0);
                WalletTransaction::create([
                    'user_id' => $locked->user_id,
                    'type' => 'debit',
                    'amount' => (float) $locked->balance_amount,
                    'balance_before' => $buyerBalance,
                    'balance_after' => $buyerBalance,
                    'description' => "Solde - Commande #{$locked->order_number}",
                    'reference_type' => 'order',
                    'reference_id' => $locked->id,
                    'metadata' => [
                        'payment_method' => $locked->balance_payment_method,
                        'payment_reference' => $locked->balance_payment_reference,
                        'balance' => true,
                    ],
                    'status' => 'completed',
                    'provider' => $locked->balance_payment_method === 'stripe_direct' ? 'stripe' : 'kpay',
                ]);
            }

            $vendor = User::find($locked->items->first()?->seller_id);
            if ($vendor) {
                $this->orders->settleOrder($locked, $vendor);
            } else {
                Log::warning('[DepositOrder] Vendeur introuvable, règlement différé', ['order_id' => $locked->id]);
            }
            $this->trace($locked, 'Solde payé par le client', null, null, 'buyer', $locked->user_id);

            return $locked;
        });

        if (!$settled) {
            return;
        }

        $this->notifyBuyer($settled, 'order_balance_paid', ['order_number' => $settled->order_number]);
        try {
            $settled->notifySellersTranslated(
                'notifications.order_balance_paid_vendor.title',
                'notifications.order_balance_paid_vendor.body',
                ['order_number' => $settled->order_number],
                ['type' => 'order_balance_paid_vendor'],
            );
        } catch (\Throwable $e) {
            Log::warning('[DepositOrder] Notification vendeur échouée: ' . $e->getMessage());
        }
    }

    /** Paiement du solde non abouti : le client peut relancer (le solde reste débloqué). */
    public function failBalancePayment(Order $order): void
    {
        $failed = DB::transaction(function () use ($order) {
            $locked = Order::whereKey($order->id)->lockForUpdate()->first();
            if (!$locked || $locked->balance_status !== Order::BALANCE_UNLOCKED || !$locked->balance_payment_method) {
                return false;
            }
            $locked->update([
                'balance_payment_method' => null,
                'balance_payment_reference' => null,
                'balance_payment_currency' => null,
                'balance_payment_amount' => null,
            ]);

            return true;
        });

        if ($failed) {
            $this->notifyBuyer($order, 'order_balance_failed', ['order_number' => $order->order_number]);
        }
    }

    /**
     * Statut du paiement du solde re-vérifié auprès du prestataire (polling mobile),
     * même logique que le premier paiement (OrderController::paymentStatus).
     */
    public function syncBalancePayment(Order $order): void
    {
        if ($order->balance_status !== Order::BALANCE_UNLOCKED || !$order->balance_payment_reference) {
            return;
        }

        try {
            if ($order->balance_payment_method === 'kpay_direct') {
                $result = (new KPayService())->checkPaymentStatus($order->balance_payment_reference);
                $status = strtoupper($result['status'] ?? 'UNKNOWN');
                if (in_array($status, ['SUCCESS', 'SUCCESSFUL', 'COMPLETED'], true)) {
                    $this->confirmBalancePayment($order);
                } elseif (in_array($status, ['FAILED', 'FAILURE', 'ERROR', 'REJECTED', 'CANCELLED', 'CANCELED'], true)) {
                    $this->failBalancePayment($order);
                }
            } elseif ($order->balance_payment_method === 'stripe_direct') {
                $intent = app(StripeService::class)->retrievePaymentIntent($order->balance_payment_reference);
                $status = strtolower($intent['status'] ?? '');
                if ($status === 'succeeded') {
                    $this->confirmBalancePayment($order);
                } elseif ($status === 'canceled') {
                    $this->failBalancePayment($order);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('[DepositOrder] Vérification du paiement du solde: ' . $e->getMessage());
        }
    }

    /**
     * Clôture au cas par cas (problème, renoncement) : l'acompte encaissé est réparti
     * entre le client (Wallet ASSO), le vendeur, le livreur, le reste revenant à ASSO.
     * Impossible une fois le solde payé (la commande est alors réglée).
     */
    public function closeWithDepositSplit(Order $order, User $employee, float $refund, float $vendorShare, float $deliveryShare, string $note): Order
    {
        $closed = DB::transaction(function () use ($order, $employee, $refund, $vendorShare, $deliveryShare, $note) {
            $locked = Order::whereKey($order->id)->lockForUpdate()->with('items', 'user')->firstOrFail();
            if (!$locked->isDepositOrder()
                || $locked->status === 'cancelled'
                || $locked->settled_at
                || $locked->balance_status === Order::BALANCE_PAID) {
                throw new \Exception(__('orders.already_processed'));
            }

            $collected = $locked->collectedAmounts();
            $deposit = round($collected['wallet'] + $collected['direct'], 2);
            if ($refund < 0 || $vendorShare < 0 || $deliveryShare < 0 || round($refund + $vendorShare + $deliveryShare, 2) > $deposit) {
                throw new \Exception(__('orders.deposit_split_exceeds', ['amount' => number_format($deposit, 0, ',', ' ')]));
            }
            $assoShare = round($deposit - $refund - $vendorShare - $deliveryShare, 2);

            // Paiement Wallet : l'acompte est encore bloqué → la part rendue est débloquée,
            // le reste est prélevé puis redistribué. Rail direct : l'argent est déjà chez
            // ASSO, la part rendue est créditée sur le Wallet du client.
            $client = $locked->user;
            if ($collected['wallet'] > 0) {
                if ($refund > 0) {
                    $this->wallet->unlockFunds($client, $refund, "Acompte rendu — Commande #{$locked->order_number}", 'order', $locked->id, ['deposit_split' => true], 'kpay');
                }
                if ($deposit - $refund > 0) {
                    $this->wallet->releaseEscrow($client, $deposit - $refund, "Acompte retenu — Commande #{$locked->order_number}", 'order', $locked->id, ['deposit_split' => true], 'kpay');
                }
            } elseif ($refund > 0) {
                $this->wallet->credit($client, $refund, null, "Acompte rendu — Commande #{$locked->order_number}", ['order_id' => $locked->id, 'refund' => true, 'deposit_split' => true], 'kpay');
            }

            $meta = ['order_id' => $locked->id, 'deposit_split' => true];
            if ($vendorShare > 0 && ($vendor = User::find($locked->items->first()?->seller_id))) {
                $this->wallet->credit($vendor, $vendorShare, null, "Part de l'acompte — Commande #{$locked->order_number}", $meta, 'kpay');
            }
            if ($deliveryShare > 0 && ($companyUser = User::find(DelivererCompany::whereKey($locked->delivery_company_id)->value('user_id')))) {
                $this->wallet->credit($companyUser, $deliveryShare, null, "Livraison — Commande #{$locked->order_number}", $meta, 'kpay');
            }
            if ($assoShare > 0 && ($platform = CommissionService::platformAccount())) {
                $this->wallet->credit($platform, $assoShare, null, "Acompte retenu par ASSO — Commande #{$locked->order_number}", $meta, 'kpay');
            }

            foreach ($locked->items as $item) {
                $item->restoreStock();
            }

            $locked->update([
                'status' => 'cancelled',
                'cancel_reason' => $note,
                'cancelled_at' => now(),
                'balance_status' => Order::BALANCE_CANCELLED,
                'payment_status' => $refund > 0 ? Order::PAYMENT_REFUNDED : $locked->payment_status,
                'refunded_at' => $refund > 0 ? now() : null,
                // Les fonds sont répartis : plus aucun remboursement ni règlement possible.
                'settled_at' => now(),
                'deposit_refund_amount' => $refund,
                'deposit_vendor_amount' => $vendorShare,
                'verification_note' => $note,
                'verified_by' => $employee->id,
                'verified_at' => $locked->verified_at ?? now(),
            ]);
            app(OrderTrackingService::class)->record($locked, 'cancelled', null, $note, 'admin', $employee->id);

            return $locked;
        });

        $this->notifyBuyer($closed, 'order_deposit_closed', [
            'order_number' => $closed->order_number,
            'amount' => number_format((float) $closed->deposit_refund_amount, 0, ',', ' '),
        ]);

        return $closed;
    }

    /**
     * Étapes affichées à l'acheteur (timeline « Mes commandes ») : done | current | todo.
     *
     * @return list<array{key: string, state: string}>
     */
    public static function timeline(Order $order): array
    {
        $tracking = (string) $order->tracking_status;
        $shipped = in_array($order->status, ['shipped', 'delivered'], true);
        $presented = $order->verification_status !== Order::VERIFICATION_PENDING;
        $verified = $order->verification_status === Order::VERIFICATION_VERIFIED;
        $balancePaid = $order->balance_status === Order::BALANCE_PAID;
        $delivered = $order->status === 'delivered';

        $done = [
            'deposit_paid' => $order->payment_status === Order::PAYMENT_PAID || $order->payment_status === Order::PAYMENT_REFUNDED,
            'supplier_order' => $order->confirmed_at !== null,
            'in_transit' => $shipped || in_array($tracking, ['handed_to_carrier', 'in_transit', 'customs'], true) || $presented,
            'presented' => $presented,
            'verification' => $verified,
            'balance' => $balancePaid,
            'handover' => $delivered,
        ];

        $steps = [];
        $currentSet = $order->status === 'cancelled';
        foreach ($done as $key => $isDone) {
            $state = $isDone ? 'done' : ($currentSet ? 'todo' : 'current');
            if (!$isDone) {
                $currentSet = true;
            }
            $steps[] = ['key' => $key, 'state' => $state];
        }

        return $steps;
    }

    /** Champs « acompte » de la commande pour l'API (acheteur, vendeur). */
    public static function present(Order $order): ?array
    {
        if (!$order->isDepositOrder()) {
            return null;
        }

        return [
            'deposit_amount' => (float) $order->deposit_amount,
            'balance_amount' => (float) $order->balance_amount,
            'balance_status' => $order->balance_status,
            'balance_payable' => $order->balance_status === Order::BALANCE_UNLOCKED && $order->status !== 'cancelled',
            'balance_paid_at' => $order->balance_paid_at?->toIso8601String(),
            'verification_status' => $order->verification_status,
            'verified_at' => $order->verified_at?->toIso8601String(),
            'deposit_refund_amount' => $order->deposit_refund_amount !== null ? (float) $order->deposit_refund_amount : null,
            'timeline' => self::timeline($order),
        ];
    }

    /** Verrou + contrôle de l'état de vérification, puis action. */
    private function transition(Order $order, array $allowed, callable $apply): Order
    {
        return DB::transaction(function () use ($order, $allowed, $apply) {
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if (!$locked->isDepositOrder()
                || $locked->status === 'cancelled'
                || !in_array($locked->verification_status, $allowed, true)) {
                throw new \Exception(__('orders.verification_transition_invalid'));
            }
            $apply($locked);

            return $locked;
        });
    }

    /**
     * Trace d'audit dans l'historique de la commande, sans changer l'étape logistique
     * (tracking_status reste la dernière étape de livraison). La note de l'employé reste
     * interne (orders.verification_note) : l'historique est visible par l'acheteur.
     */
    private function trace(Order $order, string $label, ?string $note, ?User $employee, string $actorType = 'admin', ?int $actorId = null): void
    {
        \App\Models\OrderTrackingEvent::create([
            'order_id' => $order->id,
            'step' => 'deposit_verification',
            'label' => $label,
            'actor_type' => $actorType,
            'actor_id' => $actorId ?? $employee?->id,
            'occurred_at' => now(),
        ]);
    }

    private function notifyBuyer(Order $order, string $type, array $replace): void
    {
        $buyer = $order->user;
        if (!$buyer) {
            return;
        }
        try {
            $this->fcm->sendToUser(
                $buyer,
                $buyer->translate("notifications.{$type}.title"),
                $buyer->translate("notifications.{$type}.body", $replace),
                ['type' => $type, 'order_id' => (string) $order->id, 'order_number' => $order->order_number]
            );
        } catch (\Throwable $e) {
            Log::warning("[DepositOrder] FCM {$type} échec: " . $e->getMessage());
        }
    }
}
