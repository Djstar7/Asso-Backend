<?php

namespace App\Jobs;

use App\Models\Order;
use App\Models\Supplier;
use App\Services\Suppliers\SupplierManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Commande ASSO tout juste payée : crée sa commande CJ si elle contient des produits CJ. */
class CaptureSupplierOrderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public int $orderId) {}

    public function handle(SupplierManager $suppliers): void
    {
        // Aucun produit fournisseur importé : rien à préparer (cas de la plupart des commandes).
        $order = Order::withoutGlobalScopes()->find($this->orderId);
        if ($order && \App\Models\SupplierProduct::whereNotNull('product_id')
            ->whereIn('product_id', \App\Models\OrderItem::where('order_id', $order->id)->select('product_id'))->exists()) {
            $suppliers->driver(Supplier::CJ)->orders()->capture($order);
        }
    }
}
