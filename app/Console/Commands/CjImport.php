<?php

namespace App\Console\Commands;

use App\Jobs\ImportCjProductJob;
use App\Models\Category;
use App\Models\Supplier;
use App\Models\SupplierProduct;
use App\Services\Suppliers\Cj\CjApiException;
use App\Services\Suppliers\SupplierManager;
use Illuminate\Console\Command;

/**
 * Import en masse depuis le catalogue CJ. Parcourt la recherche CJ page par
 * page (100 produits par page, 6000 au plus par recherche) et met chaque
 * produit en file d'attente.
 *
 * Exemples :
 *   php artisan cj:import --category=<id CJ> --limit=200 --dry-run
 *   php artisan cj:import --keyword="phone case" --asso-category=12 --limit=500
 *   php artisan cj:import --pid=04A22450-67F0-4617-A132-E7AE7F8963B0 --now
 */
class CjImport extends Command
{
    protected $signature = 'cj:import
        {--keyword= : Mot-clé CJ}
        {--category= : Identifiant de catégorie CJ (3e niveau)}
        {--min-price= : Prix CJ minimal (USD)}
        {--max-price= : Prix CJ maximal (USD)}
        {--asso-category= : Catégorie ASSO si la catégorie CJ n\'est pas reliée}
        {--asso-subcategory= : Sous-catégorie ASSO correspondante}
        {--limit=100 : Nombre maximal de produits}
        {--pid=* : Identifiant(s) produit CJ précis}
        {--skip-existing : Ignorer les produits déjà importés}
        {--now : Importer tout de suite, sans file d\'attente}
        {--dry-run : Lister sans rien importer}';

    protected $description = 'Importe des produits CJdropshipping dans la boutique ASSO Import Douala';

    public function handle(SupplierManager $suppliers): int
    {
        $driver = $suppliers->driver(Supplier::CJ);
        $cj = $driver->supplier();
        if (! $cj->isConnected()) {
            $this->error('CJ n’est pas connecté : testez la connexion dans Fournisseurs ou lancez cj:status.');

            return self::FAILURE;
        }

        $categoryId = $this->option('asso-category') ? (int) $this->option('asso-category') : null;
        if ($categoryId && ! Category::whereKey($categoryId)->exists()) {
            $this->error("Catégorie ASSO {$categoryId} introuvable.");

            return self::FAILURE;
        }
        $subcategoryId = $this->option('asso-subcategory') ? (int) $this->option('asso-subcategory') : null;
        $limit = max(1, (int) $this->option('limit'));

        try {
            $pids = $this->option('pid') ?: $this->collect($driver->catalog(), $limit);
        } catch (CjApiException $e) {
            $this->error($e->describe());

            return self::FAILURE;
        }

        if ($this->option('skip-existing')) {
            $existing = SupplierProduct::where('supplier_id', $cj->id)->whereIn('external_id', $pids)
                ->where('status', SupplierProduct::IMPORTED)->pluck('external_id')->all();
            $pids = array_values(array_diff($pids, $existing));
        }

        $this->info(count($pids).' produit(s) retenu(s).');
        if ($this->option('dry-run')) {
            foreach ($pids as $pid) {
                $this->line(" - {$pid}");
            }

            return self::SUCCESS;
        }

        if ($this->option('now')) {
            $importer = $driver->importer();
            $bar = $this->output->createProgressBar(count($pids));
            $summary = [];
            foreach ($pids as $pid) {
                $row = $importer->import($pid, $categoryId, $subcategoryId);
                $summary[$row->status] = ($summary[$row->status] ?? 0) + 1;
                $bar->advance();
            }
            $bar->finish();
            $this->newLine();
            foreach ($summary as $status => $n) {
                $this->line((SupplierProduct::STATUS_LABELS[$status] ?? $status)." : {$n}");
            }

            return self::SUCCESS;
        }

        foreach ($pids as $pid) {
            ImportCjProductJob::dispatch($pid, $categoryId, $subcategoryId);
        }
        $this->info('Produits mis en file d’attente : suivez-les dans Fournisseurs > Produits importés.');

        return self::SUCCESS;
    }

    /** @return list<string> */
    private function collect($catalog, int $limit): array
    {
        $filters = [
            'keyword' => $this->option('keyword'),
            'category' => $this->option('category'),
            'min_price' => $this->option('min-price'),
            'max_price' => $this->option('max-price'),
        ];
        $pids = [];
        $page = 1;
        do {
            $result = $catalog->search($filters, $page, 100);
            foreach ($result['items'] as $item) {
                $pids[$item['id']] = true;
                if (count($pids) >= $limit) {
                    break 2;
                }
            }
            $page++;
        } while ($result['items'] !== [] && $page <= $result['pages'] && $page <= 1000);

        return array_keys($pids);
    }
}
