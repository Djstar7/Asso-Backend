<?php

namespace App\Jobs\Wallet;

use App\Models\WalletTransaction;
use App\Models\PlatformWithdrawal;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

/**
 * Job schedulé qui nettoie les transactions/retraits obsolètes
 * S'exécute une fois par jour à 3h du matin via le scheduler
 *
 * Marque comme échouées:
 * - Les dépôts pending depuis plus de 24 heures
 * - Les retraits pending/processing depuis plus de 48 heures
 */
class CleanupStaleTransactionsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 300; // 5 minutes max

    public function handle()
    {
        Log::info('🧹 [CLEANUP] Starting cleanup of stale transactions...');

        $depositsUpdated = 0;
        $withdrawalsUpdated = 0;

        // 1. Nettoyer les dépôts (type = credit) pending depuis plus de 24 heures
        DB::transaction(function () use (&$depositsUpdated) {
            $staleDeposits = WalletTransaction::where('type', 'credit')
                ->where('status', 'pending')
                ->where('provider', 'kpay')
                ->where('created_at', '<', now()->subHours(24))
                ->get();

            foreach ($staleDeposits as $deposit) {
                $metadata = $deposit->metadata ?? [];
                $metadata['failed_reason'] = 'Timeout - No response after 24 hours';
                $metadata['auto_failed_at'] = now()->toISOString();
                $metadata['auto_failed_by'] = 'CleanupJob';

                $deposit->update([
                    'status' => 'failed',
                    'metadata' => $metadata,
                ]);

                $depositsUpdated++;
            }
        });

        Log::info("🧹 [CLEANUP] Marked {$depositsUpdated} stale deposit(s) as failed");

        // 2. Nettoyer les retraits pending/processing depuis plus de 7 jours.
        //    IMPORTANT : on ne marque JAMAIS "failed" à l'aveugle. On fait d'abord une
        //    vérification finale autoritative auprès de KPay (ProcessWithdrawalStatusJob) :
        //    si KPay a en réalité envoyé l'argent (SUCCESS), le retrait est complété — pas
        //    de remboursement (sinon double paiement). Seulement si KPay reste injoignable
        //    ou toujours pending on applique le timeout + remboursement (dernier recours).
        $staleWithdrawals = PlatformWithdrawal::whereIn('status', ['pending', 'processing'])
            ->where('provider', 'kpay')
            ->where('created_at', '<', now()->subDays(7))
            ->get();

        foreach ($staleWithdrawals as $withdrawal) {
            // Vérification finale synchrone (appel HTTP KPay + traitement atomique interne).
            // Hors transaction pour ne pas tenir de verrou pendant l'appel réseau.
            if ($withdrawal->kpay_reference) {
                try {
                    ProcessWithdrawalStatusJob::dispatchSync($withdrawal->id);
                } catch (\Exception $e) {
                    Log::warning('⚠️ [CLEANUP] Vérification finale KPay échouée', [
                        'withdrawal_id' => $withdrawal->id,
                        'error' => $e->getMessage(),
                    ]);
                }
                $withdrawal->refresh();
            }

            // Résolu par la vérification finale (complété ou échoué+remboursé) → rien à faire.
            if (in_array($withdrawal->status, ['completed', 'failed'])) {
                continue;
            }

            // KPay toujours injoignable/pending après 7 jours → timeout + remboursement (dernier recours).
            DB::transaction(function () use ($withdrawal, &$withdrawalsUpdated) {
                $locked = PlatformWithdrawal::whereKey($withdrawal->id)->lockForUpdate()->first();
                if (!$locked || in_array($locked->status, ['completed', 'failed'])) {
                    return; // résolu entre-temps par une exécution concurrente
                }

                $kpayResponse = $locked->kpay_response ?? [];
                $kpayResponse['failed_reason'] = 'Timeout - Aucune confirmation après 7 jours';
                $kpayResponse['auto_failed_at'] = now()->toISOString();
                $kpayResponse['auto_failed_by'] = 'CleanupJob';

                $locked->update([
                    'status' => 'failed',
                    'failure_code' => 'TIMEOUT',
                    'failure_reason' => 'Timeout - Aucune confirmation après 7 jours',
                    'kpay_response' => $kpayResponse,
                ]);

                // Marquer le débit wallet correspondant comme échoué
                WalletTransaction::where('reference_type', 'platform_withdrawal')
                    ->where('reference_id', $locked->id)
                    ->where('type', 'debit')
                    ->update(['status' => 'failed']);

                // Rembourser l'utilisateur (le wallet a été débité lors de la demande)
                $this->refundTimedOutWithdrawal($locked);

                $withdrawalsUpdated++;
            });
        }

        Log::info("🧹 [CLEANUP] Marked {$withdrawalsUpdated} stale withdrawal(s) as failed (timeout)");

        Log::info('✅ [CLEANUP] Cleanup completed', [
            'deposits_cleaned' => $depositsUpdated,
            'withdrawals_cleaned' => $withdrawalsUpdated,
            'total' => $depositsUpdated + $withdrawalsUpdated,
            'executed_at' => now()->toDateTimeString(),
        ]);

        // Optionnel : Envoyer des notifications aux admins si beaucoup de transactions ont été nettoyées
        if (($depositsUpdated + $withdrawalsUpdated) > 10) {
            Log::warning('⚠️ [CLEANUP] High number of stale transactions detected', [
                'count' => $depositsUpdated + $withdrawalsUpdated,
                'deposits' => $depositsUpdated,
                'withdrawals' => $withdrawalsUpdated,
            ]);
            // TODO: Notifier les admins via Slack/Discord/Email
        }
    }

    /**
     * Rembourser un retrait qui a timeout
     */
    private function refundTimedOutWithdrawal(PlatformWithdrawal $withdrawal): void
    {
        try {
            $user = $withdrawal->user;

            // Vérifier si un remboursement n'a pas déjà été effectué
            $existingRefund = WalletTransaction::where('reference_type', 'platform_withdrawal_refund')
                ->where('reference_id', $withdrawal->id)
                ->where('type', 'refund')
                ->exists();

            if ($existingRefund) {
                Log::debug('ℹ️ [CLEANUP] Refund already exists for timed-out withdrawal', [
                    'withdrawal_id' => $withdrawal->id,
                ]);
                return;
            }

            $currency = $withdrawal->currency ?? 'XAF';
            $balanceBefore = $user->kpayBalanceFor($currency);
            $refundAmount = $withdrawal->amount_requested;

            // Créer la transaction de remboursement
            WalletTransaction::create([
                'user_id' => $user->id,
                'type' => 'refund',
                'amount' => $refundAmount,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceBefore + $refundAmount,
                'description' => "Remboursement retrait expiré - {$withdrawal->payment_method} ({$withdrawal->payment_account})",
                'reference_type' => 'platform_withdrawal_refund',
                'reference_id' => $withdrawal->id,
                'status' => 'completed',
                'provider' => 'kpay',
                'metadata' => [
                    'withdrawal_id' => $withdrawal->id,
                    'original_amount' => $withdrawal->amount_requested,
                    'failure_reason' => 'Timeout - 48 hours expired',
                    'refunded_at' => now()->toISOString(),
                    'refunded_by' => 'CleanupJob',
                ],
            ]);

            // Créditer le wallet de l'utilisateur dans la bonne devise
            $user->creditKpay($currency, $refundAmount);

            Log::info('💰 [CLEANUP] Timed-out withdrawal refunded to user wallet', [
                'withdrawal_id' => $withdrawal->id,
                'user_id' => $user->id,
                'refund_amount' => $refundAmount,
                'currency' => $currency,
                'new_balance' => $user->kpayBalanceFor($currency),
            ]);

        } catch (\Exception $e) {
            Log::error('❌ [CLEANUP] Failed to refund timed-out withdrawal', [
                'withdrawal_id' => $withdrawal->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
