<?php

namespace App\Jobs\Wallet;

use App\Models\WalletTransaction;
use App\Models\User;
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
 * Job qui vérifie le statut d'un dépôt spécifique via l'API KPay
 * Envoie une notification FCM à l'utilisateur en cas de succès ou échec
 */
class ProcessDepositStatusJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;          // Retry 3 fois en cas d'erreur
    public $timeout = 60;       // Timeout de 60 secondes
    public $backoff = 10;       // Attendre 10 secondes entre chaque retry

    public function __construct(
        public int $walletTransactionId
    ) {}

    public function handle(KPayService $kpayService, FirebaseMessagingService $fcmService)
    {
        $deposit = WalletTransaction::find($this->walletTransactionId);

        if (!$deposit) {
            Log::warning('⚠️ [PROCESS-DEPOSIT] WalletTransaction not found', [
                'wallet_transaction_id' => $this->walletTransactionId,
            ]);
            return;
        }

        // Si déjà traité, on arrête
        if (in_array($deposit->status, ['completed', 'failed'])) {
            Log::debug('ℹ️ [PROCESS-DEPOSIT] Deposit already processed', [
                'wallet_transaction_id' => $deposit->id,
                'status' => $deposit->status,
            ]);
            return;
        }

        $metadata = $deposit->metadata ?? [];
        $reference = $metadata['provider_reference'] ?? null;

        if (!$reference) {
            Log::error('❌ [PROCESS-DEPOSIT] No KPay reference found', [
                'wallet_transaction_id' => $deposit->id,
            ]);
            return;
        }

        try {
            Log::info('🔍 [PROCESS-DEPOSIT] Checking deposit status with KPay API', [
                'wallet_transaction_id' => $deposit->id,
                'user_id' => $deposit->user_id,
                'reference' => $reference,
                'attempt' => $this->attempts(),
            ]);

            // Appeler l'API KPay pour obtenir le statut
            $statusResponse = $kpayService->checkPaymentStatus($reference);

            $status = strtoupper($statusResponse['status'] ?? 'UNKNOWN');
            $reason = $statusResponse['reason'] ?? $statusResponse['message'] ?? null;

            Log::info('📊 [PROCESS-DEPOSIT] KPay status received', [
                'wallet_transaction_id' => $deposit->id,
                'status' => $status,
                'reason' => $reason,
            ]);

            // Traiter selon le statut dans une transaction DB atomique
            DB::transaction(function () use ($deposit, $status, $reason, $statusResponse, $metadata, $fcmService) {
                // Mettre à jour les metadata avec la dernière réponse
                $metadata['last_status_check'] = now()->toISOString();
                $metadata['kpay_status'] = $status;
                $metadata['kpay_response'] = $statusResponse;
                $metadata['checked_via'] = 'job';
                $metadata['check_attempts'] = ($metadata['check_attempts'] ?? 0) + 1;

                if (in_array($status, ['SUCCESS', 'SUCCESSFUL', 'COMPLETED'])) {
                    Log::info('✅ [PROCESS-DEPOSIT] Payment SUCCESS - Completing deposit', [
                        'wallet_transaction_id' => $deposit->id,
                        'amount' => $deposit->amount,
                    ]);

                    // Mettre à jour le statut et balance_after (devise du dépôt)
                    $user = $deposit->user;
                    $currency = $metadata['currency'] ?? 'XAF';
                    $currentBalance = $user->kpayBalanceFor($currency);

                    $deposit->update([
                        'status' => 'completed',
                        'balance_after' => $currentBalance + $deposit->amount,
                        'metadata' => array_merge($metadata, [
                            'completed_via' => 'job',
                            'completed_at' => now()->toISOString(),
                        ]),
                    ]);

                    // Créditer le wallet KPay de l'utilisateur dans la bonne devise
                    $user->creditKpay($currency, $deposit->amount);

                    Log::info('💰 [PROCESS-DEPOSIT] KPay wallet credited successfully', [
                        'wallet_transaction_id' => $deposit->id,
                        'user_id' => $user->id,
                        'amount' => $deposit->amount,
                        'new_balance' => $user->fresh()->kpay_wallet_balance,
                    ]);

                    // Envoyer notification FCM de succès
                    try {
                        $fcmResult = $fcmService->sendToUser(
                            $user,
                            '💰 Dépôt réussi',
                            "Votre dépôt de {$deposit->amount} FCFA a été confirmé avec succès.",
                            [
                                'type' => 'wallet_credit',
                                'wallet_transaction_id' => (string) $deposit->id,
                                'amount' => (string) $deposit->amount,
                                'provider' => 'kpay',
                                'status' => 'completed',
                                'click_action' => 'OPEN_WALLET',
                            ]
                        );

                        if ($fcmResult['success'] ?? false) {
                            Log::info('📱 [PROCESS-DEPOSIT] FCM success notification sent', [
                                'wallet_transaction_id' => $deposit->id,
                                'user_id' => $user->id,
                                'success_count' => $fcmResult['success_count'] ?? 0,
                            ]);
                        } else {
                            Log::warning('⚠️ [PROCESS-DEPOSIT] FCM notification failed', [
                                'wallet_transaction_id' => $deposit->id,
                                'user_id' => $user->id,
                                'error' => $fcmResult['message'] ?? 'Unknown error',
                            ]);
                        }
                    } catch (\Exception $e) {
                        Log::error('❌ [PROCESS-DEPOSIT] Failed to send FCM notification', [
                            'wallet_transaction_id' => $deposit->id,
                            'error' => $e->getMessage(),
                        ]);
                    }

                } elseif (in_array($status, ['FAILED', 'FAILURE', 'ERROR', 'REJECTED', 'CANCELLED', 'CANCELED'])) {
                    Log::warning('⚠️ [PROCESS-DEPOSIT] Payment FAILED', [
                        'wallet_transaction_id' => $deposit->id,
                        'reason' => $reason,
                    ]);

                    $deposit->update([
                        'status' => 'failed',
                        'metadata' => array_merge($metadata, [
                            'failure_reason' => $reason ?? 'Payment failed',
                            'failed_at' => now()->toISOString(),
                        ]),
                    ]);

                    // Envoyer notification FCM d'échec
                    try {
                        $user = $deposit->user;
                        $fcmResult = $fcmService->sendToUser(
                            $user,
                            '❌ Dépôt échoué',
                            "Votre dépôt de {$deposit->amount} FCFA a échoué. Raison: " . ($reason ?? 'Erreur inconnue'),
                            [
                                'type' => 'wallet_credit_failed',
                                'wallet_transaction_id' => (string) $deposit->id,
                                'amount' => (string) $deposit->amount,
                                'provider' => 'kpay',
                                'status' => 'failed',
                                'reason' => $reason ?? 'Unknown error',
                                'click_action' => 'OPEN_WALLET',
                            ]
                        );

                        if ($fcmResult['success'] ?? false) {
                            Log::info('📱 [PROCESS-DEPOSIT] FCM failure notification sent', [
                                'wallet_transaction_id' => $deposit->id,
                                'user_id' => $user->id,
                                'success_count' => $fcmResult['success_count'] ?? 0,
                            ]);
                        } else {
                            Log::warning('⚠️ [PROCESS-DEPOSIT] FCM failure notification failed to send', [
                                'wallet_transaction_id' => $deposit->id,
                                'user_id' => $user->id,
                                'error' => $fcmResult['message'] ?? 'Unknown error',
                            ]);
                        }
                    } catch (\Exception $e) {
                        Log::error('❌ [PROCESS-DEPOSIT] Failed to send FCM failure notification', [
                            'wallet_transaction_id' => $deposit->id,
                            'error' => $e->getMessage(),
                        ]);
                    }

                } elseif (in_array($status, ['PENDING', 'PROCESSING', 'INITIATED'])) {
                    Log::debug('⏳ [PROCESS-DEPOSIT] Payment still pending', [
                        'wallet_transaction_id' => $deposit->id,
                        'status' => $status,
                    ]);

                    // Simplement mettre à jour les metadata
                    $deposit->update([
                        'metadata' => $metadata,
                    ]);

                } else {
                    Log::warning('⚠️ [PROCESS-DEPOSIT] Unknown status received', [
                        'wallet_transaction_id' => $deposit->id,
                        'status' => $status,
                        'response' => $statusResponse,
                    ]);

                    // Mettre à jour les metadata quand même
                    $deposit->update([
                        'metadata' => $metadata,
                    ]);
                }
            });

        } catch (\Exception $e) {
            Log::error('❌ [PROCESS-DEPOSIT] Error checking deposit status', [
                'wallet_transaction_id' => $this->walletTransactionId,
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
     * Gérer l'échec du job après tous les retries
     */
    public function failed(\Throwable $exception)
    {
        Log::error('❌ [PROCESS-DEPOSIT] Job failed after all retries', [
            'wallet_transaction_id' => $this->walletTransactionId,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);

        // TODO: Notifier un admin, créer une alerte Slack/Discord
    }
}
