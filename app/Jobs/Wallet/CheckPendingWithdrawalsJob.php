<?php

namespace App\Jobs\Wallet;

use App\Models\PlatformWithdrawal;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Job schedulé qui vérifie tous les retraits en attente
 * S'exécute toutes les minutes via le scheduler
 */
class CheckPendingWithdrawalsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 120;

    public function handle()
    {
        Log::info('🔍 [CHECK-WITHDRAWALS] Starting check for pending withdrawals...');

        // Trouver les retraits pending ou processing:
        // - Créés il y a au moins 1 minute (laisser le temps au provider de traiter)
        // - Créés il y a maximum 7 jours (on continue d'interroger KPay tant que ce n'est pas
        //   résolu ; au-delà, le CleanupJob fait une dernière vérification puis rembourse)
        // - Provider = 'kpay' (Orange Money / MTN MoMo)
        // - Updated il y a plus de 30 secondes (éviter de spammer l'API)
        $pendingWithdrawals = PlatformWithdrawal::whereIn('status', ['pending', 'processing'])
            ->where('provider', 'kpay')
            ->where('created_at', '>=', now()->subDays(7))
            ->where('created_at', '<=', now()->subMinute())
            // Éviter de vérifier trop souvent
            ->where('updated_at', '<=', now()->subSeconds(30))
            ->get();

        $count = $pendingWithdrawals->count();
        Log::info("📊 [CHECK-WITHDRAWALS] Found {$count} pending withdrawal(s) to verify");

        if ($count === 0) {
            return;
        }

        // Dispatcher un job pour chaque retrait
        foreach ($pendingWithdrawals as $withdrawal) {
            $reference = $withdrawal->kpay_reference;

            if (!$reference) {
                Log::warning('⚠️ [CHECK-WITHDRAWALS] Withdrawal without KPay reference', [
                    'withdrawal_id' => $withdrawal->id,
                    'user_id' => $withdrawal->user_id,
                ]);
                continue;
            }

            Log::debug('📤 [CHECK-WITHDRAWALS] Dispatching verification job for withdrawal', [
                'withdrawal_id' => $withdrawal->id,
                'user_id' => $withdrawal->user_id,
                'reference' => $reference,
                'amount' => $withdrawal->amount_sent,
            ]);

            // Dispatch le job de vérification sur la queue 'withdrawals'
            ProcessWithdrawalStatusJob::dispatch($withdrawal->id)
                ->onQueue('withdrawals');
        }

        Log::info("✅ [CHECK-WITHDRAWALS] Dispatched {$count} verification job(s)");
    }
}
