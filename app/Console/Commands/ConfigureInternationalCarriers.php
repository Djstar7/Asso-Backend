<?php

namespace App\Console\Commands;

use App\Models\DelivererCompany;
use App\Support\ExportTestTracking;
use App\Support\InternationalCarrierSchema;
use Database\Seeders\DhlFedexProductionSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class ConfigureInternationalCarriers extends Command
{
    protected $signature = 'delivery:configure-carriers';

    protected $description = 'Initialise DHL/FedEx et Solex seuls, avec tarifs aller/retour et agences, sans réinitialiser les données';

    public function handle(): int
    {
        if (app()->environment('production') && ExportTestTracking::enabled()) {
            $this->error('Définissez EXPORT_TEST_TRACKING_ENABLED=false puis rafraîchissez le cache de configuration avant le déploiement production.');
            return self::FAILURE;
        }
        foreach (InternationalCarrierSchema::REQUIRED_COLUMNS as $table => $columns) {
            if (!Schema::hasColumns($table, $columns)) {
                $this->error('Appliquez les migrations avant le chargement : php artisan migrate --force (table '.$table.').');
                return self::FAILURE;
            }
        }
        if ($this->call('db:seed', ['--class' => DhlFedexProductionSeeder::class, '--force' => true]) !== self::SUCCESS) {
            return self::FAILURE;
        }
        $rows = DelivererCompany::whereIn('name', ['DHL Express', 'FedEx'])->get()->map(function ($company) {
            $routes = $company->deliveryRoutes()->whereNotNull('export_config')->get();
            return [$company->name, $company->is_active ? 'Oui' : 'Non', $routes->pluck('destination_country')->unique()->count(),
                $routes->filter(fn ($r) => isset($r->export_config['return_pricing']))->count(),
                $routes->filter(fn ($r) => $r->export_config['provisional_rates'] ?? false)->count(),
                $company->agencies()->where('is_international_reference', true)->count()];
        });
        $this->table(['Transporteur', 'Actif', 'Destinations', 'Tarifs retour', 'Tarifs provisoires', 'Repères agences'], $rows->all());
        $solex = DelivererCompany::where('name', 'SOLEX')->firstOrFail();
        $this->line('SOLEX : '.($solex->is_active ? 'actif' : 'inactif').' — '.$solex->agencies()->where('is_active', true)->count().' agences actives ; tarifs de collecte existants conservés.');
        $this->line('Administration : Partenaires logistiques → DHL/FedEx → Trajets ; SOLEX → Tarifs de collecte Solex.');
        $this->line('Les tarifs initiaux et points provisoires sont à confirmer par ASSO. Aucune API ni réservation DHL/FedEx n’est activée par cette seed.');
        return self::SUCCESS;
    }
}
