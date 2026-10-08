<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Services\DisputeService;
use App\Services\OrderService;
use Illuminate\Console\Command;

/**
 * Fin de la fenêtre de contrôle de 48 h : sans action du client, la commande livrée
 * est validée automatiquement et la part du vendeur débloquée (les articles en
 * litige restent bloqués sur leur litige). Même règle pour un produit de
 * remplacement livré.
 */
class AutoValidateOrders extends Command
{
    protected $signature = 'orders:auto-validate';

    protected $description = 'Valide les commandes livrées sans réclamation après 48 h et débloque la part vendeur';

    public function handle(OrderService $orders, DisputeService $disputes): int
    {
        $validated = 0;

        Order::where('vendor_funds_status', Order::VENDOR_FUNDS_HELD)
            ->where('status', 'delivered')
            ->whereNotNull('auto_validate_at')
            ->where('auto_validate_at', '<=', now())
            ->orderBy('id')
            ->each(function (Order $order) use ($orders, &$validated) {
                try {
                    $orders->releaseVendorFunds($order, 'auto');
                    $validated++;
                } catch (\Throwable $e) {
                    $this->error("Commande #{$order->order_number} : {$e->getMessage()}");
                }
            });

        $replacements = $disputes->autoValidateReplacements();

        $this->info("{$validated} commande(s) validée(s), {$replacements} remplacement(s) validé(s).");

        return self::SUCCESS;
    }
}
