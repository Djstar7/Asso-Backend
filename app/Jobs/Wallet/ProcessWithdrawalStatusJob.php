<?php

namespace App\Jobs\Wallet;

use App\Models\PlatformWithdrawal;
use App\Models\WalletTransaction;
use App\Services\KPayService;
use App\Services\FirebaseMessagingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

/**
 * Job qui vérifie le statut d'un retrait spécifique via l'API FreeMoPay
 * Envoie une notification FCM à l'utilisateur en cas de succès ou échec
 */
class ProcessWithdrawalStatusJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;          // Retry 3 fois en cas d'erreur
    public $timeout = 60;       // Timeout de 60 secondes
    public $backoff = 10;       // Attendre 10 secondes entre chaque retry

    public function __construct(
        public int $withdrawalId
    ) {}

    public function handle(KPayService $freemopayService, FirebaseMessagingService $fcmService)
    {
        $withdrawal = PlatformWithdrawal::find($this->withdrawalId);

        if (!$withdrawal) {
            Log::warning('⚠️ [PROCESS-WITHDRAWAL] PlatformWithdrawal not found', [
                'withdrawal_id' => $this->withdrawalId,
            ]);
            return;
        }

        // Si déjà traité, on arrête
        if (in_array($withdrawal->status, ['completed', 'failed'])) {
            Log::debug('ℹ️ [PROCESS-WITHDRAWAL] Withdrawal already processed', [
                'withdrawal_id' => $withdrawal->id,
                'status' => $withdrawal->status,
            ]);
            return;
        }

        $reference = $withdrawal->kpay_reference;

        if (!$reference) {
            Log::error('❌ [PROCESS-WITHDRAWAL] No FreeMoPay reference found', [
                'withdrawal_id' => $withdrawal->id,
            ]);
            return;
        }

        try {
            Log::info('🔍 [PROCESS-WITHDRAWAL] Checking withdrawal status with FreeMoPay API', [
                'withdrawal_id' => $withdrawal->id,
                'user_id' => $withdrawal->user_id,
                'reference' => $reference,
                'attempt' => $this->attempts(),
            ]);

            // Appeler l'API FreeMoPay pour obtenir le statut
            $statusResponse = $freemopayService->checkDisbursementStatus($reference);

            $status = strtoupper($statusResponse['status'] ?? 'UNKNOWN');
            $reason = $statusResponse['reason'] ?? $statusResponse['message'] ?? null;

            Log::info('📊 [PROCESS-WITHDRAWAL] FreeMoPay status received', [
                'withdrawal_id' => $withdrawal->id,
                'status' => $status,
                'reason' => $reason,
            ]);

            // Traiter selon le statut dans une transaction DB atomique (avec lock pour éviter double-processing)
            DB::transaction(function () use ($withdrawal, $status, $reason, $statusResponse, $fcmService) {
                // Lock pour éviter le traitement concurrent
                $withdrawal = PlatformWithdrawal::whereKey($withdrawal->id)->lockForUpdate()->first();

                if (!$withdrawal) {
                    return;
                }

                // Vérifier à nouveau le statut après le lock
                if (in_array($withdrawal->status, ['completed', 'failed'])) {
                    return;
                }

                // Mettre à jour la réponse FreeMoPay
                $withdrawal->kpay_response = array_merge($withdrawal->kpay_response ?? [], [
                    'last_status_check' => now()->toISOString(),
                    'status' => $status,
                    'response' => $statusResponse,
                    'checked_via' => 'job',
                    'check_attempts' => ($withdrawal->kpay_response['check_attempts'] ?? 0) + 1,
                ]);

                if (in_array($status, ['SUCCESS', 'SUCCESSFUL', 'COMPLETED'])) {
                    Log::info('✅ [PROCESS-WITHDRAWAL] Withdrawal SUCCESS', [
                        'withdrawal_id' => $withdrawal->id,
                        'amount_sent' => $withdrawal->amount_sent,
                    ]);

                    // Marquer comme complété
                    $withdrawal->status = 'completed';
                    $withdrawal->completed_at = now();
                    $withdrawal->save();

                    // Mettre à jour la transaction wallet correspondante
                    $walletTransaction = WalletTransaction::where('reference_type', 'platform_withdrawal')
                        ->where('reference_id', $withdrawal->id)
                        ->where('type', 'debit')
                        ->first();

                    if ($walletTransaction) {
                        $walletTransaction->status = 'completed';
                        $walletTransaction->save();

                        Log::info('📝 [PROCESS-WITHDRAWAL] Wallet transaction marked as completed', [
                            'wallet_transaction_id' => $walletTransaction->id,
                        ]);
                    }

                    Log::info('💸 [PROCESS-WITHDRAWAL] Withdrawal marked as completed', [
                        'withdrawal_id' => $withdrawal->id,
                        'user_id' => $withdrawal->user_id,
                    ]);

                    // Envoyer notification FCM de succès
                    try {
                        $user = $withdrawal->user;
                        $fcmService->sendToUser(
                            $user,
                            '💸 Retrait effectué',
                            "Votre retrait de {$withdrawal->amount_sent} FCFA a été envoyé avec succès à {$withdrawal->payment_account}.",
                            [
                                'type' => 'wallet_withdrawal_completed',
                                'withdrawal_id' => (string) $withdrawal->id,
                                'amount' => (string) $withdrawal->amount_sent,
                                'provider' => $withdrawal->provider,
                                'payment_method' => $withdrawal->payment_method,
                                'payment_account' => $withdrawal->payment_account,
                                'status' => 'completed',
                                'click_action' => 'OPEN_WALLET',
                            ]
                        );

                        Log::info('📱 [PROCESS-WITHDRAWAL] FCM success notification sent', [
                            'withdrawal_id' => $withdrawal->id,
                            'user_id' => $user->id,
                        ]);
                    } catch (\Exception $e) {
                        Log::error('❌ [PROCESS-WITHDRAWAL] Failed to send FCM notification', [
                            'withdrawal_id' => $withdrawal->id,
                            'error' => $e->getMessage(),
                        ]);
                    }

                } elseif (in_array($status, ['FAILED', 'FAILURE', 'ERROR', 'REJECTED', 'CANCELLED', 'CANCELED'])) {
                    Log::warning('⚠️ [PROCESS-WITHDRAWAL] Withdrawal FAILED', [
                        'withdrawal_id' => $withdrawal->id,
                        'reason' => $reason,
                    ]);

                    // Marquer comme échoué
                    $withdrawal->status = 'failed';
                    $withdrawal->failure_code = 'KPAY_' . $status;
                    $withdrawal->failure_reason = $reason ?? 'Withdrawal failed';
                    $withdrawal->save();

                    // Mettre à jour la transaction wallet correspondante
                    $walletTransaction = WalletTransaction::where('reference_type', 'platform_withdrawal')
                        ->where('reference_id', $withdrawal->id)
                        ->where('type', 'debit')
                        ->first();

                    if ($walletTransaction) {
                        $walletTransaction->status = 'failed';
                        $walletTransaction->save();

                        Log::info('📝 [PROCESS-WITHDRAWAL] Wallet transaction marked as failed', [
                            'wallet_transaction_id' => $walletTransaction->id,
                        ]);
                    }

                    // Rembourser le montant à l'utilisateur (le wallet a été débité lors de la demande)
                    $this->refundFailedWithdrawal($withdrawal);

                    // Envoyer notification FCM d'échec
                    try {
                        $user = $withdrawal->user;
                        $fcmService->sendToUser(
                            $user,
                            '❌ Retrait échoué',
                            "Votre retrait de {$withdrawal->amount_sent} FCFA a échoué. " . ($reason ?? 'Veuillez réessayer.'),
                            [
                                'type' => 'wallet_withdrawal_failed',
                                'withdrawal_id' => (string) $withdrawal->id,
                                'amount' => (string) $withdrawal->amount_sent,
                                'provider' => $withdrawal->provider,
                                'status' => 'failed',
                                'reason' => $reason ?? 'Unknown error',
                                'click_action' => 'OPEN_WALLET',
                            ]
                        );

                        Log::info('📱 [PROCESS-WITHDRAWAL] FCM failure notification sent', [
                            'withdrawal_id' => $withdrawal->id,
                            'user_id' => $user->id,
                        ]);
                    } catch (\Exception $e) {
                        Log::error('❌ [PROCESS-WITHDRAWAL] Failed to send FCM failure notification', [
                            'withdrawal_id' => $withdrawal->id,
                            'error' => $e->getMessage(),
                        ]);
                    }

                } elseif (in_array($status, ['PENDING', 'PROCESSING', 'INITIATED', 'CREATED'])) {
                    Log::debug('⏳ [PROCESS-WITHDRAWAL] Withdrawal still pending', [
                        'withdrawal_id' => $withdrawal->id,
                        'status' => $status,
                    ]);

                    // Passer en "processing" si c'était "pending"
                    if ($withdrawal->status === 'pending') {
                        $withdrawal->status = 'processing';
                        $withdrawal->save();
                    }

                } else {
                    Log::warning('⚠️ [PROCESS-WITHDRAWAL] Unknown status received', [
                        'withdrawal_id' => $withdrawal->id,
                        'status' => $status,
                        'response' => $statusResponse,
                    ]);

                    // Sauvegarder la réponse même en cas de statut inconnu
                    $withdrawal->save();
                }
            });

        } catch (\Exception $e) {
            Log::error('❌ [PROCESS-WITHDRAWAL] Error checking withdrawal status', [
                'withdrawal_id' => $this->withdrawalId,
                'reference' => $reference,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'attempt' => $this->attempts(),
            ]);

            // Laravel va automatiquement retry selon $tries
            throw $e;
        }
    }

    /**
     * Rembourser le montant du retrait échoué
     * Crée une transaction de crédit pour rembourser l'utilisateur
     */
    private function refundFailedWithdrawal(PlatformWithdrawal $withdrawal): void
    {
        try {
            $user = $withdrawal->user;

            // Vérifier si un remboursement n'a pas déjà été effectué
            $existingRefund = WalletTransaction::where('reference_type', 'platform_withdrawal_refund')
                ->where('reference_id', $withdrawal->id)
                ->where('type', 'refund')
                ->exists();

            if ($existingRefund) {
                Log::warning('⚠️ [PROCESS-WITHDRAWAL] Refund already exists for this withdrawal', [
                    'withdrawal_id' => $withdrawal->id,
                ]);
                return;
            }

            $balanceBefore = $user->kpay_wallet_balance ?? 0;

            // Créditer le montant demandé (amount_requested) car c'est ce qui a été débité initialement
            $refundAmount = $withdrawal->amount_requested;

            // Créer la transaction de remboursement
            WalletTransaction::create([
                'user_id' => $user->id,
                'type' => 'refund',
                'amount' => $refundAmount,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceBefore + $refundAmount,
                'description' => "Remboursement retrait échoué - {$withdrawal->payment_method} ({$withdrawal->payment_account})",
                'reference_type' => 'platform_withdrawal_refund',
                'reference_id' => $withdrawal->id,
                'status' => 'completed',
                'provider' => 'kpay',
                'metadata' => [
                    'withdrawal_id' => $withdrawal->id,
                    'original_amount' => $withdrawal->amount_requested,
                    'failure_reason' => $withdrawal->failure_reason,
                    'refunded_at' => now()->toISOString(),
                ],
            ]);

            // Créditer le wallet de l'utilisateur
            $user->increment('kpay_wallet_balance', $refundAmount);

            Log::info('💰 [PROCESS-WITHDRAWAL] Withdrawal refunded to user wallet', [
                'withdrawal_id' => $withdrawal->id,
                'user_id' => $user->id,
                'refund_amount' => $refundAmount,
                'new_balance' => $user->fresh()->kpay_wallet_balance,
            ]);

        } catch (\Exception $e) {
            Log::error('❌ [PROCESS-WITHDRAWAL] Failed to refund withdrawal', [
                'withdrawal_id' => $withdrawal->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Gérer l'échec du job après tous les retries
     */
    public function failed(\Throwable $exception)
    {
        Log::error('❌ [PROCESS-WITHDRAWAL] Job failed after all retries', [
            'withdrawal_id' => $this->withdrawalId,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);

        // TODO: Notifier un admin, créer une alerte Slack/Discord
    }
}
