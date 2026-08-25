<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\FirebaseMessagingService;
use App\Services\StripeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Webhook Stripe — finalise le cycle de vie des virements IBAN (payout) initiés
 * par WalletController::initiateStripeWithdrawal.
 *
 * Endpoint public (pas d'auth) protégé par la vérification de signature Stripe
 * (secret webhook). Événements traités :
 *  - `payout.paid`   : les fonds ont atteint l'IBAN → retrait `completed`.
 *  - `payout.failed` : le versement a échoué → retrait `failed` + **remboursement**
 *    du solde wallet du vendeur (crédit de compensation).
 *
 * Ces événements sont des **Connect events** (émis sur le compte connecté du
 * vendeur) : `event.account` = id du compte Connect. Le retrait est retrouvé par
 * `stripe_payout_id`, avec repli sur `stripe_transfer_id` (via metadata).
 *
 * Idempotent : chaque transition est verrouillée (lockForUpdate) et ne s'applique
 * que depuis un statut non terminal — un webhook rejoué n'a aucun effet.
 */
class StripeWebhookController extends Controller
{
    public function __construct(
        private StripeService $stripe,
        private FirebaseMessagingService $fcm,
    ) {
    }

    /** POST /v1/stripe/webhook */
    public function handle(Request $request)
    {
        $payload = $request->getContent();
        $sigHeader = (string) $request->header('Stripe-Signature');

        try {
            $event = $this->stripe->constructWebhookEvent($payload, $sigHeader);
        } catch (\Throwable $e) {
            Log::warning('[StripeWebhook] Signature invalide ou secret manquant', [
                'error' => $e->getMessage(),
            ]);
            return response()->json(['message' => 'Invalid signature'], 400);
        }

        Log::info('[StripeWebhook] Event reçu', [
            'type' => $event->type,
            'account' => $event->account ?? null,
        ]);

        try {
            switch ($event->type) {
                case 'payout.paid':
                    $this->handlePayoutPaid($event->data->object);
                    break;

                case 'payout.failed':
                    $this->handlePayoutFailed($event->data->object);
                    break;

                case 'checkout.session.completed':
                    // Session Checkout terminée : le paiement carte est encaissé.
                    $this->handlePaymentIntentSucceeded($event->data->object);
                    break;

                case 'payment_intent.succeeded':
                    $this->handlePaymentIntentSucceeded($event->data->object);
                    break;

                case 'payment_intent.payment_failed':
                case 'payment_intent.canceled':
                    $this->handlePaymentIntentFailed($event->data->object);
                    break;

                case 'account.updated':
                    $this->handleAccountUpdated($event->data->object);
                    break;

                default:
                    // Événement non géré : on accuse simplement réception.
                    break;
            }
        } catch (\Throwable $e) {
            Log::error('[StripeWebhook] Erreur de traitement', [
                'type' => $event->type,
                'error' => $e->getMessage(),
            ]);
            // 500 → Stripe réessaiera la livraison.
            return response()->json(['message' => 'Processing error'], 500);
        }

        return response()->json(['received' => true]);
    }

    /** Règlement d'un payout : logique partagée avec `stripe:reconcile-payouts`. */
    private function handlePayoutPaid(object $payout): void
    {
        app(\App\Services\StripePayoutSettlementService::class)->settlePaid($payout, 'StripeWebhook');
    }

    private function handlePayoutFailed(object $payout): void
    {
        app(\App\Services\StripePayoutSettlementService::class)->settleFailed($payout, 'StripeWebhook');
    }

    /**
     * payment_intent.succeeded → confirme l'encaissement carte (source d'autorité
     * robuste, même si l'app est fermée). Route selon `metadata.asso_kind`.
     * Idempotent : confirmBookingPayment ne re-crédite pas une réservation déjà payée.
     */
    private function handlePaymentIntentSucceeded(object $pi): void
    {
        $kind = $pi->metadata->asso_kind ?? null;

        if ($kind === 'diaspo_booking') {
            $bookingId = (int) ($pi->metadata->booking_id ?? 0);
            $booking = \App\Models\DiaspoBooking::find($bookingId);
            if ($booking) {
                app(DiaspoController::class)->confirmBookingPayment($booking);
                Log::info('[StripeWebhook] ✅ Réservation diaspo payée (carte)', [
                    'booking_id' => $bookingId,
                    'payment_intent' => $pi->id ?? null,
                ]);
            }
        } elseif ($kind === 'order') {
            $orderId = (int) ($pi->metadata->order_id ?? 0);
            $order = \App\Models\Order::find($orderId);
            if ($order) {
                app(\App\Services\OrderService::class)->confirmStripeOrderPayment($order);
                Log::info('[StripeWebhook] ✅ Commande payée (carte)', [
                    'order_id' => $orderId,
                    'payment_intent' => $pi->id ?? null,
                ]);
            }
        }
    }

    /**
     * payment_intent.payment_failed | canceled → échec de l'encaissement carte.
     * Route selon `metadata.asso_kind` ; libère les kg réservés (idempotent).
     */
    private function handlePaymentIntentFailed(object $pi): void
    {
        $kind = $pi->metadata->asso_kind ?? null;

        if ($kind === 'diaspo_booking') {
            $bookingId = (int) ($pi->metadata->booking_id ?? 0);
            $booking = \App\Models\DiaspoBooking::find($bookingId);
            if ($booking) {
                app(DiaspoController::class)->failBookingPayment($booking);
                Log::warning('[StripeWebhook] ❌ Paiement carte réservation diaspo échoué', [
                    'booking_id' => $bookingId,
                    'payment_intent' => $pi->id ?? null,
                ]);
            }
        } elseif ($kind === 'order') {
            $orderId = (int) ($pi->metadata->order_id ?? 0);
            $order = \App\Models\Order::find($orderId);
            if ($order) {
                app(\App\Services\OrderService::class)->failStripeOrderPayment($order);
                Log::warning('[StripeWebhook] ❌ Paiement carte commande échoué', [
                    'order_id' => $orderId,
                    'payment_intent' => $pi->id ?? null,
                ]);
            }
        }
    }

    /**
     * account.updated → synchronise l'état de vérification du compte vendeur.
     *
     * Stripe peut désactiver à tout moment un compte déjà validé chez nous (pièce
     * justificative demandée, informations expirées). Sans cette synchronisation, le
     * vendeur resterait `approved` côté ASSO et ses virements échoueraient un par un.
     */
    private function handleAccountUpdated(object $account): void
    {
        $accountId = $account->id ?? null;
        if (!$accountId) {
            return;
        }

        $user = \App\Models\User::where('stripe_account_id', $accountId)->first();
        if (!$user) {
            return;
        }

        $transfers = (string) ($account->capabilities->transfers ?? 'unknown');
        $ready = $transfers === 'active' && (bool) ($account->payouts_enabled ?? false);

        Log::info('[StripeWebhook] account.updated', [
            'user_id' => $user->id,
            'transfers' => $transfers,
            'payouts_enabled' => $account->payouts_enabled ?? null,
            'internal_status' => $user->stripe_account_status,
        ]);

        // Compte validé chez nous mais désactivé par Stripe : on le remet en attente.
        if (!$ready && $user->stripe_account_status === 'approved') {
            $due = array_values(array_unique(array_merge(
                (array) ($account->requirements->currently_due ?? []),
                (array) ($account->requirements->past_due ?? []),
            )));

            $user->update([
                'stripe_account_status' => 'pending',
                'stripe_verified_at' => null,
                'stripe_rejection_reason' => 'Informations complémentaires demandées par Stripe'
                    . (empty($due) ? '.' : ' : ' . implode(', ', array_slice($due, 0, 6)) . '.'),
            ]);

            Log::warning('[StripeWebhook] ⚠️ Compte de virement repassé en attente', [
                'user_id' => $user->id,
                'requirements_due' => $due,
            ]);

            $this->notifyUser(
                $user,
                'Compte de virement à mettre à jour',
                'Notre partenaire bancaire demande des informations complémentaires avant '
                    . "d'autoriser vos virements. Mettez à jour votre compte de virement.",
                ['type' => 'stripe_account_requirements', 'action' => 'open_stripe_connect'],
            );
        }
    }

    /** Notification FCM à un utilisateur (best-effort). */
    private function notifyUser(\App\Models\User $user, string $title, string $body, array $data): void
    {
        try {
            $this->fcm->sendToUser($user, $title, $body, $data);
        } catch (\Throwable $e) {
            Log::error('[StripeWebhook] Notification FCM échouée: ' . $e->getMessage());
        }
    }

}
