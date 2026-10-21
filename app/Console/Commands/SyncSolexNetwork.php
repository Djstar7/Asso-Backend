<?php

namespace App\Console\Commands;

use App\Models\DelivererCompany;
use App\Models\DeliveryCityGrid;
use App\Models\DeliveryRoute;
use App\Models\DeliveryTerritory;
use Database\Seeders\SolexNetworkSeeder;
use Illuminate\Console\Command;

class SyncSolexNetwork extends Command
{
    protected $signature = 'solex:sync {--dry-run : Inventaire sans aucune écriture} {--activate : Option de compatibilité ; l’activation est désormais systématique}';

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

            return self::SUCCESS;
        }
        $seeder = new SolexNetworkSeeder;
        $seeder->setCommand($this);
        $seeder->run();

        return self::SUCCESS;
    }
}
