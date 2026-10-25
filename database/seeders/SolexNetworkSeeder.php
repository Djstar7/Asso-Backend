<?php

namespace Database\Seeders;

use App\Models\DelivererCompany;
use App\Models\DeliveryAgency;
use App\Models\DeliveryCityGrid;
use App\Models\DeliveryRoute;
use App\Models\DeliveryTerritory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/** Synchronisation explicite : réactivation ; déploiement groupé : activations existantes conservées. */
class SolexNetworkSeeder extends Seeder
{
    public function run(bool $preserveExistingActivation = false): void
    {
        $reference = json_decode(file_get_contents(database_path('data/cameroon-delivery-territories.json')), true, flags: JSON_THROW_ON_ERROR);
        $rows = collect($reference['territories']);
        foreach ([1 => 10, 2 => 58, 3 => 360] as $level => $count) {
            throw_unless($rows->where('level', $level)->count() === $count, \RuntimeException::class, 'Référentiel Cameroun incomplet.');
        }

        DB::transaction(function () use ($rows, $reference, $preserveExistingActivation) {
            foreach ($rows as $row) {
                DeliveryTerritory::firstOrCreate(['code' => $row['code']],
                    collect($row)->only(['level', 'parent_code', 'name', 'name_en', 'latitude', 'longitude'])->all() + [
                        'metadata' => collect($row)->only(['hub_city', 'hub_latitude', 'hub_longitude', 'hub_department_code'])->all()
                            + ['source' => $reference['url'], 'reference_version' => $reference['retrieved_at']],
                    ]);
            }
            $company = DelivererCompany::firstOrCreate(['name' => 'SOLEX'], [
                'service_type' => DelivererCompany::SERVICE_INTERCITY,
                'service_mode' => DelivererCompany::MODE_AGENCY,
                'prices_exclude_vat' => true, 'is_active' => true,
                'territorial_network_enabled' => true,
                'description' => 'Collecte en boutique, transport national et livraison à domicile ou retrait en agence.',
            ]);
            $departments = $rows->where('level', 2)->values();
            if (! $preserveExistingActivation) {
                $company->update(['is_active' => true, 'territorial_network_enabled' => true]);
            }
            foreach ($departments as $department) {
                $arrondissements = $rows->where('level', 3)->where('parent_code', $department['code'])->sortBy('code')->values();
                $zones = $arrondissements->map(fn ($a, $i) => [
                    'code' => $i + 1, 'territory_code' => $a['code'], 'label' => $a['name'],
                    'center_latitude' => $a['latitude'], 'center_longitude' => $a['longitude'],
                    'radius_km' => 10, 'priority' => 0, 'is_active' => true,
                    'quarters' => [], 'provisional' => true,
                ])->all();
                $agencyZone = collect($zones)->sortBy(fn ($z) => DeliveryCityGrid::distanceKm(
                    $z['center_latitude'], $z['center_longitude'], $department['hub_latitude'], $department['hub_longitude']
                ))->first()['code'];
                $legacyAddress = 'Centre-ville de '.$department['hub_city'].' — point exact à confirmer par SOLEX';
                $previousAddress = $department['hub_city'].' — chef-lieu (département : '.$department['name'].'). Adresse exacte du relais SOLEX à préciser.';
                $departmentAddress = $department['hub_city'].'-'.$department['name'];
                DeliveryCityGrid::firstOrCreate([
                    'deliverer_company_id' => $company->id, 'department_code' => $department['code'],
                ], [
                    'city' => $department['hub_city'], 'country' => 'CM', 'coverage_scope' => 'department', 'zones' => $zones,
                    'agency_zone' => $agencyZone, 'vehicles' => $this->vehicles($zones), 'is_active' => true,
                ]);
                // Le chef-lieu est un repère de desserte, pas une adresse physique d'agence.
                $agency = DeliveryAgency::firstOrCreate(['code' => 'SOLEX-'.$department['code']], [
                    'deliverer_company_id' => $company->id, 'name' => 'SOLEX — '.$department['hub_city'],
                    'city' => $department['hub_city'], 'address' => $departmentAddress,
                    'phone' => $company->phone ?: 'À confirmer', 'is_active' => true,
                    'country' => 'CM', 'latitude' => $department['hub_latitude'], 'longitude' => $department['hub_longitude'],
                    'coordinate_precision' => 'city_center', 'is_provisional' => true,
                ]);
                if (in_array($agency->address, [$legacyAddress, $previousAddress], true)) {
                    $agency->update(['address' => $departmentAddress]);
                }
                if (($agency->latitude === null || $agency->longitude === null)
                    && $agency->address === $departmentAddress) {
                    $agency->update(['country' => 'CM', 'latitude' => $department['hub_latitude'],
                        'longitude' => $department['hub_longitude'], 'coordinate_precision' => 'city_center', 'is_provisional' => true]);
                }
                if (blank($agency->address) || blank($agency->phone)) {
                    $agency->update([
                        'address' => $agency->address ?: $departmentAddress,
                        'phone' => $agency->phone ?: ($company->phone ?: 'À confirmer'),
                        'is_active' => $preserveExistingActivation ? $agency->is_active : true,
                    ]);
                }
            }
            foreach ($rows->where('level', 1)->values() as $i => $from) {
                foreach ($rows->where('level', 1)->values()->slice($i + 1) as $to) {
                    $this->route($company, 'interregional', $from, $to);
                }
            }
            foreach ($departments->groupBy('parent_code') as $regionalDepartments) {
                foreach ($regionalDepartments->values() as $i => $from) {
                    foreach ($regionalDepartments->values()->slice($i + 1) as $to) {
                        $this->route($company, 'intraregional', $from, $to);
                    }
                }
            }
            if (! $preserveExistingActivation) {
                // Réactiver aussi les données déjà présentes, sans remplacer leurs réglages.
                $company->cityGrids()->whereNotNull('department_code')->get()->each(function ($grid) {
                    $zones = collect($grid->zones)->map(fn ($zone) => array_replace($zone, ['is_active' => true]))->all();
                    $vehicles = $grid->vehicles;
                    if (!collect($vehicles)->contains('code', 'heavy')) {
                        $vehicles[] = $this->heavyVehicle();
                    }
                    $grid->update(['is_active' => true, 'zones' => $zones, 'vehicles' => $vehicles]);
                });
                $company->deliveryRoutes()->whereNotNull('network_key')->update(['is_active' => true]);
                $company->agencies()->where('code', 'like', 'SOLEX-%')->update(['is_active' => true]);
            }
        });
        $this->command?->info('SOLEX : 10 régions, 58 départements, 360 cercles, 45 liaisons nationales et 158 régionales préparés.');
        if ($preserveExistingActivation) {
            $this->command?->info('Réseau Solex complété sans réinitialiser les tarifs, centres, rayons ou activations existants.');
            return;
        }
        $this->command?->info('Mode territorial, 58 départements, 360 cercles et 203 liaisons actifs. Centres, rayons et tarifs conservés. Points de desserte aux chefs-lieux ; adresses exactes des relais à préciser.');
    }

    private function route(DelivererCompany $company, string $level, array $from, array $to): void
    {
        $legacy = $company->deliveryRoutes()->whereNull('network_key')->get()->first(
            fn ($r) => $r->serves('CM', $from['hub_city'], 'CM', $to['hub_city'])
        );
        $km = DeliveryCityGrid::distanceKm($from['hub_latitude'], $from['hub_longitude'], $to['hub_latitude'], $to['hub_longitude']);
        $band = $km < 150 ? [2500, 3500, 100] : ($km < 400 ? [3000, 4500, 150] : ($km < 800 ? [4000, 6000, 200] : [5000, 8000, 300]));
        DeliveryRoute::firstOrCreate(['network_key' => 'solex-'.$company->id.'-'.$from['code'].'-'.$to['code']], [
            'deliverer_company_id' => $company->id, 'network_level' => $level,
            'origin_territory_code' => $from['code'], 'destination_territory_code' => $to['code'],
            'origin_country' => 'CM', 'origin_city' => $from['hub_city'],
            'destination_country' => 'CM', 'destination_city' => $to['hub_city'],
            'bidirectional' => true, 'is_active' => true, 'lead_time' => $km < 300 ? '24 h à 48 h' : '48 h à 96 h',
            'pricing_data' => ($legacy?->pricing_data ?? [
                'ranges' => [['min' => 0, 'max' => 2, 'price' => $band[0], 'label' => 'Plis & paquets'],
                    ['min' => 2, 'max' => 10, 'price' => $band[1], 'label' => 'Colis']],
                'extra_per_kg' => $band[2],
            ]) + ['provisional' => true, 'source_route_id' => $legacy?->id],
        ]);
    }

    private function vehicles(array $zones): array
    {
        $vehicles = [];
        foreach ([['moto', 'Moto', 30, 1000], ['tricycle', 'Tricycle', 300, 2000],
            ['600kg', 'Camionnette 600 kg', 600, 3000], ['1t', 'Camion 1 tonne', 1000, 4000]] as [$code, $label, $max, $base]) {
            $prices = [];
            foreach ($zones as $from) {
                foreach ($zones as $to) {
                    if ($from['code'] > $to['code']) {
                        continue;
                    }
                    $km = DeliveryCityGrid::distanceKm($from['center_latitude'], $from['center_longitude'], $to['center_latitude'], $to['center_longitude']);
                    $prices[$from['code'].'-'.$to['code']] = (int) round($base * ($km < 4 ? 1 : ($km < 8 ? 1.5 : ($km < 15 ? 2 : 2.5))));
                }
            }
            $vehicles[] = ['code' => $code, 'label' => $label, 'max_weight_kg' => $max,
                'lead_time' => 'À confirmer', 'prices' => $prices];
        }

        $vehicles[] = $this->heavyVehicle();
        return $vehicles;
    }

    private function heavyVehicle(): array
    {
        return ['code' => 'heavy', 'label' => 'Transport lourd au kilo', 'min_weight_kg' => 1000,
            'max_weight_kg' => null, 'lead_time' => 'À confirmer', 'prices' => [],
            'base_vehicle' => '1t', 'billing' => 'excess_per_kg', 'included_weight_kg' => 1000];
    }
}
