<?php

namespace App\Console\Commands;

use App\Models\Supplier;
use App\Services\Suppliers\SupplierManager;
use Illuminate\Console\Command;

/**
 * Inscrit les produits importés aux alertes CJ (CJ n'envoie les alertes
 * produit et stock que pour les produits inscrits) et désinscrit les produits
 * retirés, dans la limite du niveau du compte CJ.
 */
class CjSubscribeProducts extends Command
{
    protected $signature = 'cj:subscribe-products';

    protected $description = 'Met à jour la liste des produits suivis par les alertes CJ';

    public function handle(SupplierManager $suppliers): int
    {
        $driver = $suppliers->driver(Supplier::CJ);
        $supplier = $driver->supplier();
        $webhooks = $driver->webhooks();
        if (! $supplier->is_active || ! $supplier->isConnected() || ! $webhooks->isEnabled()) {
            $this->warn('Alertes CJ non activées : rien à faire.');

            return self::SUCCESS;
        }

        $result = $webhooks->syncSubscriptions();
        $this->info("{$result['subscribed']} produit(s) inscrit(s), {$result['unsubscribed']} désinscrit(s), {$result['failed']} refusé(s).");
        if ($result['error']) {
            $supplier->recordError($result['error']);
            $this->error($result['error']);

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
