<?php

namespace App\Jobs;

use App\Models\Supplier;
use App\Models\SupplierOrder;
use App\Services\Suppliers\Cj\CjApiException;
use App\Services\Suppliers\SupplierManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Envoi et paiement d'une commande chez CJ (voir CjOrders::submit). Une seule
 * tâche à la fois par commande ; chaque étape reprend là où la précédente
 * s'est arrêtée.
 */
class SubmitCjOrderJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $timeout = 300;

    public int $uniqueFor = 1800;

    public function __construct(public int $supplierOrderId, public bool $force = false) {}

    public function uniqueId(): string
    {
        return 'cj-order:'.$this->supplierOrderId;
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300, 900, 1800];
    }

    public function handle(SupplierManager $suppliers): void
    {
        $supplierOrder = SupplierOrder::find($this->supplierOrderId);
        if (! $supplierOrder) {
            return;
        }
        try {
            $suppliers->driver(Supplier::CJ)->orders()->submit($supplierOrder, $this->force);
        } catch (CjApiException $e) {
            // Limite d'appels ou CJ indisponible : nouvel essai plus tard.
            if ($this->attempts() >= $this->tries) {
                $supplierOrder->fail(SupplierOrder::FAILED, 'CJ injoignable après plusieurs essais : '.$e->describe());

                return;
            }
            $this->release($this->backoff()[min($this->attempts() - 1, 3)]);
        }
    }

    public function failed(\Throwable $e): void
    {
        SupplierOrder::find($this->supplierOrderId)?->fail(SupplierOrder::FAILED, $e->getMessage());
    }
}
