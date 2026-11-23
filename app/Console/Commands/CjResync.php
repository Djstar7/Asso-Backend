<?php

namespace App\Console\Commands;

use App\Jobs\ImportCjProductJob;
use App\Models\Supplier;
use App\Models\SupplierProduct;
use App\Models\SupplierWebhookEvent;
use Illuminate\Console\Command;

/**
 * Rattrapage planifié : relit chez CJ les fiches qui n'ont pas été mises à
 * jour depuis un moment (prix, stocks, déclinaisons, retrait de la vente).
 * Indispensable : CJ n'envoie pas d'alerte pour un changement de prix seul et
 * ne renvoie jamais une alerte ratée. Le taux du dollar est aussi repris.
 */
class CjResync extends Command
{
    protected $signature = 'cj:resync
        {--hours= : fiches non relues depuis ce nombre d\'heures (défaut : réglage resync_after_hours)}
        {--limit=5000 : nombre maximal de fiches mises en file}
        {--with-delisted : relire aussi les produits retirés (peuvent revenir en vente)}
        {--dry-run : compter sans rien mettre en file}';

    protected $description = 'Met en file la relecture chez CJ des produits importés (prix, stocks, disponibilité)';

    public function handle(): int
    {
        $supplier = Supplier::forCode(Supplier::CJ);
        if (! $supplier->is_active || ! $supplier->isConnected()) {
            $this->warn('CJ n’est pas actif ou pas connecté : rien à faire.');

            return self::SUCCESS;
        }

        $hours = (int) ($this->option('hours') ?? $supplier->setting('resync_after_hours', 20));
        $statuses = [SupplierProduct::IMPORTED, SupplierProduct::NO_SHIPPING, SupplierProduct::ERROR];
        if ($this->option('with-delisted')) {
            $statuses[] = SupplierProduct::DELISTED;
        }

        $query = SupplierProduct::where('supplier_id', $supplier->id)
            ->whereIn('status', $statuses)
            ->whereNotNull('product_id')
            ->where(fn ($q) => $q->whereNull('last_synced_at')->orWhere('last_synced_at', '<', now()->subHours(max(0, $hours))))
            ->orderByRaw('last_synced_at is null desc')->orderBy('last_synced_at')
            ->limit(max(1, (int) $this->option('limit')));

        $pids = $query->pluck('external_id');
        if ($this->option('dry-run')) {
            $this->info($pids->count().' fiche(s) à relire.');

            return self::SUCCESS;
        }
        $pids->each(fn ($pid) => ImportCjProductJob::dispatch((string) $pid));

        $kept = (int) config('suppliers.cj.webhook_events_keep_days', 30);
        $pruned = SupplierWebhookEvent::where('supplier_id', $supplier->id)->where('created_at', '<', now()->subDays($kept))->delete();

        $supplier->putSettings(['last_resync_at' => now()->toIso8601String(), 'last_resync_count' => $pids->count()]);
        $this->info($pids->count()." fiche(s) mise(s) en file de relecture. {$pruned} ancienne(s) alerte(s) supprimée(s).");

        return self::SUCCESS;
    }
}
