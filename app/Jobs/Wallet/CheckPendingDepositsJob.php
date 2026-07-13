<?php

namespace App\Jobs\Wallet;

use App\Models\WalletTransaction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Job schedulé qui vérifie tous les dépôts en attente
 * S'exécute toutes les 30 secondes via le scheduler
 */
class CheckPendingDepositsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 120;

    public function handle()
    {
        Log::info('🔍 [CHECK-DEPOSITS] Starting check for pending deposits...');

        // Trouver les dépôts en attente de type 'credit' (recharges):
        // - Créés il y a au moins 30 secondes (laisser le temps au provider de traiter)
        // - Créés il y a maximum 24 heures (après ça, le CleanupJob s'en occupe)
        // - Avec provider = 'kpay' (Orange Money / MTN MoMo)
        $pendingDeposits = WalletTransaction::where('type', 'credit')
            ->where('status', 'pending')
            ->where('provider', 'kpay')
            ->where('created_at', '>=', now()->subHours(24))
            ->where('created_at', '<=', now()->subSeconds(30))
            ->get();

        $count = $pendingDeposits->count();
        Log::info("📊 [CHECK-DEPOSITS] Found {$count} pending deposit(s) to verify");

        if ($count === 0) {
            return;
        }

        // Dispatcher un job pour chaque dépôt
        foreach ($pendingDeposits as $deposit) {
            $metadata = $deposit->metadata ?? [];
            $reference = $metadata['provider_reference'] ?? null;

            if (!$reference) {
                Log::warning('⚠️ [CHECK-DEPOSITS] Deposit without FreeMoPay reference', [
                    'wallet_transaction_id' => $deposit->id,
                    'user_id' => $deposit->user_id,
                ]);
                continue;
            }

            Log::debug('📤 [CHECK-DEPOSITS] Dispatching verification job for deposit', [
                'wallet_transaction_id' => $deposit->id,
                'user_id' => $deposit->user_id,
                'reference' => $reference,
                'amount' => $deposit->amount,
            ]);

            // Dispatch le job de vérification sur la queue 'deposits'
            ProcessDepositStatusJob::dispatch($deposit->id)
                ->onQueue('deposits');
        }

        Log::info("✅ [CHECK-DEPOSITS] Dispatched {$count} verification job(s)");
    }
}
