<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\DeliveryZone;
use Illuminate\Support\Facades\Http;

class FillDeliveryZoneCities extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'delivery:fill-cities {--force : Remplir même les zones qui ont déjà une ville}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Remplit automatiquement le champ "city" des zones de livraison via reverse geocoding (Nominatim)';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('🌍 Début du remplissage automatique des villes...');
        $this->newLine();

        // Récupérer les zones sans ville ou toutes si --force
        $query = DeliveryZone::whereNotNull('center_latitude')
            ->whereNotNull('center_longitude');

        if (!$this->option('force')) {
            $query->whereNull('city');
        }

        $zones = $query->get();

        if ($zones->isEmpty()) {
            $this->info('✅ Toutes les zones ont déjà une ville définie.');
            return Command::SUCCESS;
        }

        $this->info("📦 {$zones->count()} zone(s) à traiter");
        $this->newLine();

        $progressBar = $this->output->createProgressBar($zones->count());
        $progressBar->start();

        $updated = 0;
        $failed = 0;

        foreach ($zones as $zone) {
            try {
                $city = $this->getCityFromCoordinates(
                    (float) $zone->center_latitude,
                    (float) $zone->center_longitude
                );

                if ($city) {
                    $zone->update(['city' => $city]);
                    $updated++;
                    $progressBar->advance();
                } else {
                    $failed++;
                    $progressBar->advance();
                }

                // Respecter les limites de l'API Nominatim (1 req/sec)
                sleep(1);

            } catch (\Exception $e) {
                $this->error("\n❌ Erreur pour zone #{$zone->id}: " . $e->getMessage());
                $failed++;
                $progressBar->advance();
            }
        }

        $progressBar->finish();
        $this->newLine(2);

        // Résumé
        $this->info('═══════════════════════════════════════');
        $this->info('📊 RÉSUMÉ');
        $this->info('═══════════════════════════════════════');
        $this->info("✅ Zones mises à jour : {$updated}");
        if ($failed > 0) {
            $this->warn("⚠️  Échecs : {$failed}");
        }
        $this->info('═══════════════════════════════════════');

        return Command::SUCCESS;
    }

    /**
     * Récupère le nom de la ville via reverse geocoding (Nominatim OSM)
     */
    private function getCityFromCoordinates(float $lat, float $lon): ?string
    {
        try {
            $response = Http::timeout(10)
                ->withHeaders([
                    'User-Agent' => 'AssoApp/1.0'
                ])
                ->get('https://nominatim.openstreetmap.org/reverse', [
                    'lat' => $lat,
                    'lon' => $lon,
                    'format' => 'json',
                    'addressdetails' => 1,
                    'zoom' => 10,
                ]);

            if ($response->successful()) {
                $data = $response->json();
                $address = $data['address'] ?? [];

                // Essayer différents champs pour extraire la ville
                $cityFields = [
                    'city',
                    'town',
                    'municipality',
                    'village',
                    'state',
                    'county'
                ];

                foreach ($cityFields as $field) {
                    if (!empty($address[$field])) {
                        return $address[$field];
                    }
                }

                // Fallback: utiliser le display_name et extraire la première partie
                if (!empty($data['display_name'])) {
                    $parts = explode(',', $data['display_name']);
                    return trim($parts[0]);
                }
            }

            return null;

        } catch (\Exception $e) {
            $this->error("Erreur reverse geocoding: " . $e->getMessage());
            return null;
        }
    }
}
