<?php

namespace App\Console\Commands;

use App\Models\DelivererCompany;
use App\Models\DeliveryCityGrid;
use App\Models\DeliveryRoute;
use App\Models\DeliveryTerritory;
use App\Models\Order;
use Database\Seeders\SolexNetworkSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncSolexNetwork extends Command
{
    protected $signature = 'solex:sync
        {--dry-run : Inventaire sans aucune écriture}
        {--replace-legacy : Retirer les anciennes grilles, zones et routes SOLEX en conservant les références des commandes}
        {--activate : Option de compatibilité ; l’activation est désormais systématique}';

    protected $description = 'Synchronise et active tout le réseau national SOLEX par arrondissement (rayons).';

    public function handle(): int
    {
        if ($this->option('dry-run')) {
            $company = DelivererCompany::where('name', 'SOLEX')->first();
            $this->table(['Élément', 'Présents', 'Cible'], [
                ['Régions', DeliveryTerritory::where('level', 1)->count(), 10],
                ['Départements', DeliveryTerritory::where('level', 2)->count(), 58],
                ['Arrondissements', DeliveryTerritory::where('level', 3)->count(), 360],
                ['Grilles départementales', DeliveryCityGrid::where('deliverer_company_id', $company?->id)->whereNotNull('department_code')->count(), 58],
                ['Liaisons nationales', DeliveryRoute::where('deliverer_company_id', $company?->id)->where('network_level', 'interregional')->count(), 45],
                ['Liaisons régionales', DeliveryRoute::where('deliverer_company_id', $company?->id)->where('network_level', 'intraregional')->count(), 158],
            ]);
            if ($this->option('replace-legacy') && $company) {
                $this->table(['Ancienne configuration SOLEX', 'À supprimer', 'À conserver inactive'], $this->replaceLegacy($company, true));
            }

            return self::SUCCESS;
        }
        DB::transaction(function () {
            $seeder = new SolexNetworkSeeder;
            $seeder->setCommand($this);
            $seeder->run();
            if ($this->option('replace-legacy')) {
                $company = DelivererCompany::where('name', 'SOLEX')->firstOrFail();
                $this->table(['Ancienne configuration SOLEX', 'Supprimés', 'Conservés inactifs'], $this->replaceLegacy($company, false));
                $this->info('Ancienne configuration retirée. Compte SOLEX, commandes, paiements et autres transporteurs conservés.');
            }
        });

        return self::SUCCESS;
    }

    private function replaceLegacy(DelivererCompany $company, bool $dryRun): array
    {
        $groups = [
            ['Grilles urbaines', $company->cityGrids()->whereNull('department_code'), 'delivery_city_grid_id'],
            ['Trajets', $company->deliveryRoutes()->whereNull('network_key'), 'delivery_route_id'],
            ['Zones locales', $company->deliveryZones(), 'delivery_zone_id'],
        ];
        $summary = [];
        foreach ($groups as [$label, $query, $orderColumn]) {
            $deleted = 0;
            $retained = 0;
            // Verrouiller les parents avant de vérifier leurs références empêche
            // une nouvelle commande de s'y rattacher au milieu du nettoyage.
            $records = $dryRun ? $query->get() : $query->lockForUpdate()->get();
            foreach ($records as $record) {
                if (Order::where($orderColumn, $record->id)->exists()) {
                    $retained++;
                    if (! $dryRun) {
                        $record->update(['is_active' => false]);
                    }
                } else {
                    $deleted++;
                    if (! $dryRun) {
                        $record->delete();
                    }
                }
            }
            $summary[] = [$label, $deleted, $retained];
        }

        return $summary;
    }
}
