<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\WalletTransaction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Diagnostic (et réparation optionnelle) des commandes kpay_direct « héritées »,
 * c.-à-d. créées AVANT le fix du règlement unifié (commit 6429584).
 *
 * Sous l'ancien code, confirmKpayOrderPayment passait la commande directement en
 * status='confirmed' et créditait le vendeur via pending_earnings — court-circuitant
 * la validation vendeur qui pose l'escrow wallet. Résultat : ces commandes n'ont
 * jamais d'escrow vendeur/livreur et ne peuvent pas être livrées proprement.
 *
 * Détection d'une commande héritée : kpay_direct + payment_status='paid' + statut
 * avancé (confirmed/preparing/shipped/delivered) SANS aucune wallet_transaction
 * d'escrow rattachée à un vendeur de la commande (preuve que validate() n'a pas tourné).
 */
class DiagnoseLegacyKpayOrders extends Command
{
    protected $signature = 'kpay:diagnose-legacy-orders
        {--repair : Répare automatiquement le cas SÛR (status=confirmed non encore en préparation) : retour en pending + rollback pending_earnings}';

    protected $description = 'Diagnostique les commandes kpay_direct héritées (avant fix règlement) et répare le cas sûr avec --repair.';

    public function handle(): int
    {
        $repair = (bool) $this->option('repair');

        $orders = Order::where('payment_method', 'kpay_direct')
            ->where('payment_status', 'paid')
            ->whereIn('status', ['confirmed', 'preparing', 'shipped', 'delivered'])
            ->with('items')
            ->orderBy('id')
            ->get();

        // Ne garder que les commandes SANS escrow vendeur (= héritées)
        $legacy = $orders->filter(function (Order $order) {
            $sellerIds = $order->items->pluck('seller_id')->unique()->all();
            if (empty($sellerIds)) {
                return false;
            }
            $hasSellerEscrow = WalletTransaction::where('reference_type', 'order')
                ->where('reference_id', $order->id)
                ->whereIn('user_id', $sellerIds)
                ->exists();
            return !$hasSellerEscrow;
        })->values();

        if ($legacy->isEmpty()) {
            $this->info('✅ Aucune commande kpay_direct héritée détectée. Rien à faire.');
            return Command::SUCCESS;
        }

        $this->warn("⚠️  {$legacy->count()} commande(s) kpay_direct héritée(s) détectée(s) :");
        $this->newLine();

        $rows = [];
        $repairable = collect();
        foreach ($legacy as $order) {
            $safe = $order->status === 'confirmed'; // pas encore en préparation/livraison
            if ($safe) {
                $repairable->push($order);
            }
            $rows[] = [
                $order->id,
                $order->order_number,
                $order->status,
                number_format((float) $order->total, 0, ',', ' ') . ' XAF',
                $safe ? '✅ auto (→ pending)' : '✋ manuel',
            ];
        }

        $this->table(['ID', 'N° commande', 'Statut', 'Total', 'Réparation'], $rows);
        $this->newLine();
        $this->line("• <fg=green>auto</> : réparable en toute sécurité (retour en 'pending' pour re-validation vendeur).");
        $this->line("• <fg=yellow>manuel</> : déjà en préparation/livraison → escrow à poser à la main (risque, à traiter au cas par cas).");
        $this->newLine();

        if (!$repair) {
            $this->info("🔎 Mode diagnostic (lecture seule). Relancez avec --repair pour corriger le cas sûr.");
            return Command::SUCCESS;
        }

        if ($repairable->isEmpty()) {
            $this->warn("Aucune commande auto-réparable. Les commandes 'manuel' doivent être traitées à la main.");
            return Command::SUCCESS;
        }

        if (!$this->confirm("Réparer {$repairable->count()} commande(s) (retour en 'pending' + rollback pending_earnings) ?", true)) {
            $this->info('Annulé.');
            return Command::SUCCESS;
        }

        $repaired = 0;
        foreach ($repairable as $order) {
            DB::transaction(function () use ($order, &$repaired) {
                $locked = Order::whereKey($order->id)->lockForUpdate()->with('items')->first();
                if (!$locked || $locked->status !== 'confirmed') {
                    return; // état changé entre-temps
                }

                // Rollback du pending_earnings ajouté par l'ancien code (subtotal par vendeur)
                $sellerTotals = [];
                foreach ($locked->items as $item) {
                    $sellerTotals[$item->seller_id] = ($sellerTotals[$item->seller_id] ?? 0) + (float) $item->total_price;
                }
                foreach ($sellerTotals as $sellerId => $amount) {
                    // Ne pas passer en négatif si le solde a déjà été consommé ailleurs (calcul en PHP → portable).
                    $current = (float) (DB::table('users')->where('id', $sellerId)->value('pending_earnings') ?? 0);
                    DB::table('users')
                        ->where('id', $sellerId)
                        ->update(['pending_earnings' => max(0, $current - (float) $amount)]);
                }

                // Retour dans le flux normal : le vendeur re-valide → escrow posé correctement
                $locked->update([
                    'status' => 'pending',
                    'confirmed_at' => null,
                ]);
                $repaired++;
            });
        }

        $this->info("✅ {$repaired} commande(s) réparée(s) → repassées en 'pending' pour re-validation vendeur.");
        return Command::SUCCESS;
    }
}
