<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\Supplier;
use App\Models\SupplierOrder;
use App\Models\SupplierProduct;
use App\Services\Suppliers\Cj\CjApiException;
use App\Services\Suppliers\SupplierManager;
use Illuminate\Console\Command;

/**
 * Rattrapage des commandes CJ (les alertes CJ ne sont jamais renvoyées) :
 * - commandes ASSO payées dont la commande CJ n'a pas été préparée ;
 * - commandes CJ non payées dont la commande ASSO a été annulée entre-temps ;
 * - état, transporteur et suivi des commandes payées chez CJ.
 */
class CjSyncOrders extends Command
{
    protected $signature = 'cj:sync-orders {--minutes=60 : relire les commandes CJ non relues depuis ce nombre de minutes}';

    protected $description = 'Prépare les commandes CJ manquantes et relit l’état des commandes en cours chez CJ';

    public function handle(SupplierManager $suppliers): int
    {
        $driver = $suppliers->driver(Supplier::CJ);
        $supplier = $driver->supplier();
        $orders = $driver->orders();

        $productIds = SupplierProduct::where('supplier_id', $supplier->id)->whereNotNull('product_id')->select('product_id');
        $captured = 0;
        Order::withoutGlobalScopes()
            ->where('payment_status', Order::PAYMENT_PAID)->where('status', 'pending')->where('is_wholesale', false)
            ->where('created_at', '>=', now()->subDays(30))
            ->whereHas('items', fn ($q) => $q->whereIn('product_id', $productIds))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('supplier_orders')
                ->whereColumn('supplier_orders.order_id', 'orders.id')->where('supplier_orders.supplier_id', $supplier->id))
            ->eachById(function (Order $order) use ($orders, &$captured) {
                if ($orders->capture($order)) {
                    $captured++;
                }
            });

        $closed = 0;
        SupplierOrder::where('supplier_id', $supplier->id)
            ->whereIn('status', [SupplierOrder::AWAITING_APPROVAL, SupplierOrder::NEEDS_REVIEW, SupplierOrder::AWAITING_BALANCE, SupplierOrder::FAILED])
            ->whereHas('order', fn ($q) => $q->where('status', 'cancelled'))
            ->eachById(function (SupplierOrder $supplierOrder) use ($orders, &$closed) {
                $orders->cancel($supplierOrder, 'Commande ASSO annulée avant l’envoi à CJ.', null);
                $closed++;
            });

        $synced = 0;
        $errors = 0;
        if ($supplier->isConnected()) {
            SupplierOrder::where('supplier_id', $supplier->id)->whereIn('status', SupplierOrder::OPEN)
                ->where(fn ($q) => $q->whereNull('last_synced_at')->orWhere('last_synced_at', '<', now()->subMinutes((int) $this->option('minutes'))))
                ->eachById(function (SupplierOrder $supplierOrder) use ($orders, &$synced, &$errors) {
                    try {
                        $orders->sync($supplierOrder);
                        $synced++;
                    } catch (CjApiException $e) {
                        $errors++;
                        $supplierOrder->forceFill(['last_error' => 'Suivi CJ indisponible : '.$e->describe(), 'last_synced_at' => now()])->save();
                    }
                });
        }

        $this->info("{$captured} commande(s) CJ préparée(s), {$closed} fermée(s), {$synced} relue(s) chez CJ, {$errors} erreur(s).");

        return self::SUCCESS;
    }
}
