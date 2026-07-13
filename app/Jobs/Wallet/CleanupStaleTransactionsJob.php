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

        DB::transaction(function () use (&$depositsUpdated, &$withdrawalsUpdated) {

            // 1. Nettoyer les dépôts (type = credit) pending depuis plus de 24 heures
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

            Log::info("🧹 [CLEANUP] Marked {$depositsUpdated} stale deposit(s) as failed");

            // 2. Nettoyer les retraits pending ou processing depuis plus de 48 heures
            $staleWithdrawals = PlatformWithdrawal::whereIn('status', ['pending', 'processing'])
                ->where('provider', 'kpay')
                ->where('created_at', '<', now()->subHours(48))
                ->get();

            foreach ($staleWithdrawals as $withdrawal) {
                $freemopayResponse = $withdrawal->kpay_response ?? [];
                $freemopayResponse['failed_reason'] = 'Timeout - No response after 48 hours';
                $freemopayResponse['auto_failed_at'] = now()->toISOString();
                $freemopayResponse['auto_failed_by'] = 'CleanupJob';

                $withdrawal->update([
                    'status' => 'failed',
                    'failure_code' => 'TIMEOUT',
                    'failure_reason' => 'Timeout - No response after 48 hours',
                    'kpay_response' => $freemopayResponse,
                ]);

                // IMPORTANT: Rembourser l'utilisateur si le montant a déjà été débité
                $this->refundTimedOutWithdrawal($withdrawal);

                $withdrawalsUpdated++;
            }

            Log::info("🧹 [CLEANUP] Marked {$withdrawalsUpdated} stale withdrawal(s) as failed");
        });

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

            $balanceBefore = $user->kpay_wallet_balance ?? 0;
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

            // Créditer le wallet de l'utilisateur
            $user->increment('kpay_wallet_balance', $refundAmount);

            Log::info('💰 [CLEANUP] Timed-out withdrawal refunded to user wallet', [
                'withdrawal_id' => $withdrawal->id,
                'user_id' => $user->id,
                'refund_amount' => $refundAmount,
                'new_balance' => $user->fresh()->kpay_wallet_balance,
            ]);

        } catch (\Exception $e) {
            Log::error('❌ [CLEANUP] Failed to refund timed-out withdrawal', [
                'withdrawal_id' => $withdrawal->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
