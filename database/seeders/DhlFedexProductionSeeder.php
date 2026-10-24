<?php

namespace Database\Seeders;

use App\Services\InternationalCarrierDemoService;
use App\Support\ExportTestTracking;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/** Standalone carrier configuration, without freight imports, orders or payments. */
class DhlFedexProductionSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production') && ExportTestTracking::enabled()) {
            throw new \RuntimeException('Définissez EXPORT_TEST_TRACKING_ENABLED=false puis rafraîchissez le cache de configuration avant le déploiement production.');
        }
        DB::transaction(function () {
            $this->callWith(SolexNetworkSeeder::class, ['preserveExistingActivation' => true]);
            // Creating missing routes must not reactivate a carrier or its Solex agency.
            $counts = app(InternationalCarrierDemoService::class)->load(allowInactiveAgency: true, preserveExistingActivation: true);
            $this->call([SolexCollectionPricingSeeder::class, InternationalReturnPricingSeeder::class, InternationalCarrierAgenciesSeeder::class]);
            $this->command?->info("DHL/FedEx : {$counts['destinations']} destinations par transporteur, tarifs aller/retour et collecte Solex initialisés sans écraser les réglages existants.");
        });
    }
}
