<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PlatformWithdrawal;
use App\Models\WalletTransaction;
use App\Services\FirebaseMessagingService;
use App\Services\StripeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

    /**
     * payout.paid → marque le retrait comme `completed`.
     */
    private function handlePayoutPaid(object $payout): void
    {
        DB::transaction(function () use ($payout) {
            $withdrawal = $this->lockWithdrawalForPayout($payout);
            if (!$withdrawal) {
                Log::warning('[StripeWebhook] payout.paid : retrait introuvable', [
                    'payout_id' => $payout->id ?? null,
                ]);
                return;
            }

            if ($withdrawal->isCompleted()) {
                return; // idempotent
            }

            $withdrawal->stripe_payout_id = $withdrawal->stripe_payout_id ?? ($payout->id ?? null);
            $withdrawal->markAsCompleted($payout->id ?? '', $this->payoutToArray($payout));

            // Aligner la transaction wallet liée (pending → completed).
            WalletTransaction::where('reference_type', 'platform_withdrawal')
                ->where('reference_id', $withdrawal->id)
                ->where('provider', 'stripe')
                ->update(['status' => 'completed']);

            Log::info('[StripeWebhook] ✅ Retrait complété', [
                'withdrawal_id' => $withdrawal->id,
                'payout_id' => $payout->id ?? null,
            ]);

            $this->notify(
                $withdrawal,
                '✅ Virement effectué',
                "Votre virement de {$withdrawal->amount_sent} {$withdrawal->currency} a bien été versé sur votre compte bancaire.",
                'wallet_withdrawal_completed',
            );
        });
    }

    /**
     * payout.failed → marque le retrait comme `failed` et **rembourse** le wallet.
     */
    private function handlePayoutFailed(object $payout): void
    {
        DB::transaction(function () use ($payout) {
            $withdrawal = $this->lockWithdrawalForPayout($payout);
            if (!$withdrawal) {
                Log::warning('[StripeWebhook] payout.failed : retrait introuvable', [
                    'payout_id' => $payout->id ?? null,
                ]);
                return;
            }

            // N'agir qu'une seule fois, et jamais sur un retrait déjà terminal.
            if ($withdrawal->isFailed() || $withdrawal->isCompleted()) {
                return; // idempotent
            }

            $user = $withdrawal->user;
            $amount = (float) $withdrawal->amount_requested;
            $currency = $withdrawal->currency;

            if ($user) {
                // Recréditer le solde débité à l'initiation du retrait.
                $balanceBefore = $user->kpayBalanceFor($currency);
                $user->creditKpay($currency, $amount);
                $balanceAfter = $balanceBefore + $amount;

                WalletTransaction::create([
                    'user_id' => $user->id,
                    'type' => 'refund',
                    'amount' => $amount,
                    'balance_before' => $balanceBefore,
                    'balance_after' => $balanceAfter,
                    'description' => 'Remboursement virement bancaire échoué',
                    'status' => 'completed',
                    'provider' => 'stripe',
                    'reference_type' => 'platform_withdrawal',
                    'reference_id' => $withdrawal->id,
                    'metadata' => [
                        'reason' => $payout->failure_message ?? $payout->failure_code ?? 'payout_failed',
                        'payout_id' => $payout->id ?? null,
                        'refunded_at' => now()->toIso8601String(),
                    ],
                ]);

                // La transaction de débit initiale devient "failed".
                WalletTransaction::where('reference_type', 'platform_withdrawal')
                    ->where('reference_id', $withdrawal->id)
                    ->where('provider', 'stripe')
                    ->where('type', 'debit')
                    ->update(['status' => 'failed']);
            }

            $withdrawal->stripe_response = $this->payoutToArray($payout);
            $withdrawal->markAsFailed(
                (string) ($payout->failure_code ?? 'stripe_payout_failed'),
                (string) ($payout->failure_message ?? 'Le versement vers votre IBAN a échoué.'),
            );

            Log::warning('[StripeWebhook] ❌ Retrait échoué + remboursé', [
                'withdrawal_id' => $withdrawal->id,
                'payout_id' => $payout->id ?? null,
                'refunded' => (bool) $user,
            ]);

            $this->notify(
                $withdrawal,
                '⚠️ Virement échoué',
                "Votre virement de {$amount} {$currency} a échoué. Le montant a été recrédité sur votre portefeuille.",
                'wallet_withdrawal_failed',
            );
        });
    }

    /**
     * Retrouve et VERROUILLE le retrait correspondant au payout Stripe.
     * Priorité : stripe_payout_id ; repli : stripe_transfer_id (via metadata).
     */
    private function lockWithdrawalForPayout(object $payout): ?PlatformWithdrawal
    {
        $payoutId = $payout->id ?? null;

        if ($payoutId) {
            $withdrawal = PlatformWithdrawal::where('provider', 'stripe')
                ->where('stripe_payout_id', $payoutId)
                ->lockForUpdate()
                ->first();
            if ($withdrawal) {
                return $withdrawal;
            }
        }

        // Repli : le payout que nous avons créé porte transfer_id dans ses metadata.
        $transferId = $payout->metadata->transfer_id ?? null;
        if ($transferId) {
            return PlatformWithdrawal::where('provider', 'stripe')
                ->where('stripe_transfer_id', $transferId)
                ->lockForUpdate()
                ->first();
        }

        return null;
    }

    /** Sérialise l'objet payout Stripe pour l'audit (stripe_response). */
    private function payoutToArray(object $payout): array
    {
        if (method_exists($payout, 'toArray')) {
            return $payout->toArray();
        }
        return json_decode(json_encode($payout), true) ?? [];
    }

    /** Notification FCM best-effort (jamais bloquante). */
    private function notify(PlatformWithdrawal $withdrawal, string $title, string $body, string $type): void
    {
        try {
            $user = $withdrawal->user;
            if (!$user) {
                return;
            }
            $this->fcm->sendToUser($user, $title, $body, [
                'type' => $type,
                'provider' => 'stripe',
                'currency' => $withdrawal->currency,
                'amount' => $withdrawal->amount_sent,
                'withdrawal_id' => $withdrawal->id,
                'transaction_reference' => $withdrawal->transaction_reference,
            ]);
        } catch (\Throwable $e) {
            Log::error('[StripeWebhook] Notification FCM échouée: ' . $e->getMessage());
        }
    }
}
