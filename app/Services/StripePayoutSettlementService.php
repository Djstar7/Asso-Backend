<?php

namespace App\Services;

use App\Models\PlatformWithdrawal;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Clôture d'un virement IBAN : passage du retrait à `completed` ou `failed`.
 *
 * Deux chemins y mènent, d'où ce service partagé :
 *  - le **webhook** Stripe (`payout.paid` / `payout.failed`), chemin nominal ;
 *  - la commande **`stripe:reconcile-payouts`**, filet de sécurité qui interroge
 *    Stripe pour les retraits restés en attente — indispensable tant qu'aucun
 *    endpoint public n'est déclaré, et utile en production si un événement se perd.
 *
 * Idempotent : chaque transition est verrouillée (lockForUpdate) et ne s'applique
 * que depuis un statut non terminal, donc rejouer un événement n'a aucun effet.
 */
class StripePayoutSettlementService
{
    public function __construct(private FirebaseMessagingService $fcm)
    {
    }

    /** payout.paid → retrait `completed`. Retourne true si une transition a eu lieu. */
    public function settlePaid(object $payout, string $source = 'StripeReconcile'): bool
    {
        return (bool) DB::transaction(function () use ($payout, $source) {
            $withdrawal = $this->lockWithdrawalForPayout($payout);
            if (!$withdrawal) {
                Log::warning("[{$source}] payout.paid : retrait introuvable", [
                    'payout_id' => $payout->id ?? null,
                ]);
                return false;
            }

            if ($withdrawal->isCompleted()) {
                return false; // idempotent
            }

            $withdrawal->stripe_payout_id = $withdrawal->stripe_payout_id ?? ($payout->id ?? null);
            $withdrawal->markAsCompleted($payout->id ?? '', $this->payoutToArray($payout));

            // Aligner la transaction wallet liée (pending → completed).
            WalletTransaction::where('reference_type', 'platform_withdrawal')
                ->where('reference_id', $withdrawal->id)
                ->where('provider', 'stripe')
                ->update(['status' => 'completed']);

            Log::info("[{$source}] ✅ Retrait complété", [
                'withdrawal_id' => $withdrawal->id,
                'payout_id' => $payout->id ?? null,
            ]);

            $amount = $withdrawal->payout_amount ?? $withdrawal->amount_sent;
            $currency = $withdrawal->payout_currency ?? $withdrawal->currency;

            $this->notify(
                $withdrawal,
                'Virement effectué',
                "Votre virement de {$amount} {$currency} a bien été versé sur votre compte bancaire.",
                'wallet_withdrawal_completed',
            );

            return true;
        });
    }

    /** payout.failed → retrait `failed` + remboursement du portefeuille. */
    public function settleFailed(object $payout, string $source = 'StripeReconcile'): bool
    {
        return (bool) DB::transaction(function () use ($payout, $source) {
            $withdrawal = $this->lockWithdrawalForPayout($payout);
            if (!$withdrawal) {
                Log::warning("[{$source}] payout.failed : retrait introuvable", [
                    'payout_id' => $payout->id ?? null,
                ]);
                return false;
            }

            // N'agir qu'une seule fois, et jamais sur un retrait déjà terminal.
            if ($withdrawal->isFailed() || $withdrawal->isCompleted()) {
                return false; // idempotent
            }

            $user = $withdrawal->user;
            // Remboursement DANS LA DEVISE DÉBITÉE (le portefeuille du vendeur), pas
            // dans celle du virement : ce sont deux devises différentes.
            $amount = (float) $withdrawal->amount_requested;
            $currency = $withdrawal->currency;

            if ($user) {
                $balanceBefore = $user->kpayBalanceFor($currency);
                $user->creditKpay($currency, $amount);

                WalletTransaction::create([
                    'user_id' => $user->id,
                    'type' => 'refund',
                    'amount' => $amount,
                    'balance_before' => $balanceBefore,
                    'balance_after' => $balanceBefore + $amount,
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

            Log::warning("[{$source}] ❌ Retrait échoué + remboursé", [
                'withdrawal_id' => $withdrawal->id,
                'payout_id' => $payout->id ?? null,
                'refunded' => (bool) $user,
            ]);

            $this->notify(
                $withdrawal,
                'Virement échoué',
                "Votre virement de {$amount} {$currency} a échoué. Le montant a été recrédité sur votre portefeuille.",
                'wallet_withdrawal_failed',
            );

            return true;
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
            Log::error('[StripePayoutSettlement] Notification FCM échouée: ' . $e->getMessage());
        }
    }
}
