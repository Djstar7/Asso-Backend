<?php

namespace App\Jobs;

use App\Models\Supplier;
use App\Models\SupplierProduct;
use App\Services\Suppliers\Cj\CjApiException;
use App\Services\Suppliers\SupplierManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/**
 * Import d'un produit CJ en arrière-plan. Les appels CJ sont espacés par
 * CjClient (1 requête/s pour tout le compte) : un lot de 1000 produits prend
 * environ une heure, sans bloquer le back-office.
 */
class ImportCjProductJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 4;

    public int $timeout = 300;

    public int $uniqueFor = 3600;

    public function __construct(
        public string $pid,
        public ?int $categoryId = null,
        public ?int $subcategoryId = null,
    ) {}

    public function uniqueId(): string
    {
        return 'cj:'.$this->pid;
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(SupplierManager $suppliers): void
    {
        try {
            $suppliers->driver(Supplier::CJ)->importer()->import($this->pid, $this->categoryId, $this->subcategoryId);
        } catch (CjApiException $e) {
            // Limite d'appels ou CJ indisponible : on réessaie plus tard.
            $this->release($this->backoff()[min($this->attempts() - 1, 2)]);
        }
    }

    public function failed(\Throwable $e): void
    {
        $supplier = Supplier::forCode(Supplier::CJ);
        SupplierProduct::firstOrCreate(
            ['supplier_id' => $supplier->id, 'external_id' => $this->pid],
            ['name' => $this->pid]
        )->update(['status' => SupplierProduct::ERROR, 'last_error' => Str::limit($e->getMessage(), 1000)]);
    }
}
