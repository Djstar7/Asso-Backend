<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Console\Command;

class BackfillKpayOrderTransactions extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kpay:backfill-order-transactions {--dry-run : Affiche ce qui serait créé sans rien écrire}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Crée les traces manquantes dans wallet_transactions pour les commandes kpay_direct déjà payées (historique des paiements).';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->warn('🔎 Mode simulation (--dry-run) : aucune écriture ne sera effectuée.');
        }

        // Commandes payées via KPay direct
        $orders = Order::where('payment_method', 'kpay_direct')
            ->where('payment_status', 'paid')
            ->orderBy('id')
            ->get();

        if ($orders->isEmpty()) {
            $this->info('✅ Aucune commande kpay_direct payée à traiter.');
            return Command::SUCCESS;
        }

        $this->info("📦 {$orders->count()} commande(s) kpay_direct payée(s) trouvée(s).");

        $created = 0;
        $skipped = 0;

        $progressBar = $this->output->createProgressBar($orders->count());
        $progressBar->start();

        foreach ($orders as $order) {
            // Idempotence : ne pas dupliquer une trace déjà existante pour cette commande.
            $exists = WalletTransaction::where('reference_type', 'order')
                ->where('reference_id', $order->id)
                ->where('type', 'debit')
                ->exists();

            if ($exists) {
                $skipped++;
                $progressBar->advance();
                continue;
            }

            if (!$dryRun) {
                $buyerBalance = (float) (User::where('id', $order->user_id)->value('kpay_wallet_balance') ?? 0);

                $tx = new WalletTransaction([
                    'user_id' => $order->user_id,
                    'type' => 'debit',
                    'amount' => (float) $order->total,
                    'balance_before' => $buyerBalance,
                    'balance_after' => $buyerBalance,
                    'description' => "Achat - Commande #{$order->order_number}",
                    'reference_type' => 'order',
                    'reference_id' => $order->id,
                    'metadata' => [
                        'payment_method' => 'kpay_direct',
                        'payment_reference' => $order->payment_reference,
                        'subtotal' => (float) $order->subtotal,
                        'delivery_fee' => (float) $order->delivery_fee,
                        'backfilled' => true,
                    ],
                    'status' => 'completed',
                    'provider' => 'kpay',
                ]);

                // On aligne la date de la trace sur la date de la commande pour un historique
                // cohérent (created_at n'est pas dans $fillable, on le force ici).
                $tx->created_at = $order->confirmed_at ?? $order->updated_at ?? $order->created_at;
                $tx->save();
            }

            $created++;
            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine(2);

        $this->info($dryRun ? '🔎 Simulation terminée.' : '✅ Rétro-remplissage terminé.');
        $this->table(
            ['Statistique', 'Valeur'],
            [
                ['Commandes analysées', $orders->count()],
                [$dryRun ? 'Traces à créer' : 'Traces créées', $created],
                ['Déjà présentes (ignorées)', $skipped],
            ]
        );

        return Command::SUCCESS;
    }
}
