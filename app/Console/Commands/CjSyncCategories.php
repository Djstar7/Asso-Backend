<?php

namespace App\Console\Commands;

use App\Models\Supplier;
use App\Services\Suppliers\Cj\CjApiException;
use App\Services\Suppliers\SupplierManager;
use Illuminate\Console\Command;

/** Récupère les catégories CJ (les correspondances ASSO déjà faites sont gardées). */
class CjSyncCategories extends Command
{
    protected $signature = 'cj:sync-categories';

    protected $description = 'Synchronise les catégories CJdropshipping';

    public function handle(SupplierManager $suppliers): int
    {
        try {
            $count = $suppliers->driver(Supplier::CJ)->catalog()->syncCategories();
        } catch (CjApiException $e) {
            $this->error($e->describe());

            return self::FAILURE;
        }
        $this->info("{$count} catégories CJ synchronisées.");

        return self::SUCCESS;
    }
}
