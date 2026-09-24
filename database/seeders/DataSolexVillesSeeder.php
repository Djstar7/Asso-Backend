<?php

namespace Database\Seeders;

use App\Models\DelivererCompany;
use App\Models\DeliveryCityGrid;
use Illuminate\Database\Seeder;

/**
 * SOLEX — livraison urbaine (zone à zone) dans toutes les villes du réseau, sur le
 * modèle de la grille de Douala. Complète DataSolexSeeder (les trajets interurbains).
 *
 * Pour chaque ville : des zones de quartiers, les 4 véhicules de SOLEX (moto, tricycle,
 * camionnette 600 kg, camion 1 t) et le prix zone de départ → zone d'arrivée. Le nombre
 * de zones suit la taille de la ville (7 à Yaoundé, 5 dans les chefs-lieux de région,
 * 3 dans les petites villes).
 *
 * Les PRIX reprennent exactement le barème de Douala : prix = base du véhicule × palier,
 * avec base 1 000 / 2 000 / 3 000 / 4 000 F HT et palier 1 (même zone), 1,5 / 2 / 2,5
 * selon la distance entre les centres des deux zones. Les QUARTIERS sont réels, leurs
 * POSITIONS sont approximatives : à corriger sur la carte de l'admin (ou via le bouton
 * « Localiser tous les quartiers non placés »).
 *
 * Relançable sans dégât : une ville déjà créée n'est jamais réécrite, on ne fait que
 * remplir les zones restées vides et la zone d'agence si elle manque.
 */
class DataSolexVillesSeeder extends Seeder
{
    /** Véhicules SOLEX et prix HT de base (même zone), identiques à la grille de Douala. */
    private const VEHICLES = [
        ['code' => 'moto', 'label' => 'Moto', 'max_weight_kg' => 30, 'lead_time' => '1 h à 3 h', 'base' => 1000],
        ['code' => 'tricycle', 'label' => 'Tricycle', 'max_weight_kg' => 300, 'lead_time' => '2 h à 4 h', 'base' => 2000],
        ['code' => '600kg', 'label' => 'Camionnette 600 kg', 'max_weight_kg' => 600, 'lead_time' => '3 h à 6 h', 'base' => 3000],
        ['code' => '1t', 'label' => 'Camion 1 tonne', 'max_weight_kg' => 1000, 'lead_time' => '3 h à 6 h', 'base' => 4000],
    ];

    /** Paliers de prix : même zone ×1, puis ×1,5 / ×2 / ×2,5 selon la distance entre zones. */
    private const STEP_NEAR_KM = 4.0;
    private const STEP_FAR_KM = 8.0;

    /**
     * Ville => zones de couverture. Zone => quartier => [latitude, longitude].
     * La zone 1 est toujours le centre commercial : c'est là que se trouve l'agence SOLEX.
     */
    private const CITIES = [
        'Yaoundé' => ['country' => 'CM', 'zones' => [
            1 => ['Centre-ville' => [3.8667, 11.5167], 'Poste Centrale' => [3.8670, 11.5180], 'Hôtel de Ville' => [3.8680, 11.5190],
                  'Mokolo' => [3.8790, 11.5060], 'Briqueterie' => [3.8780, 11.5100], 'Messa' => [3.8700, 11.4950]],
            2 => ['Bastos' => [3.8900, 11.5100], 'Nlongkak' => [3.8800, 11.5200], 'Elig-Essono' => [3.8760, 11.5200],
                  'Tsinga' => [3.8850, 11.4980], 'Djoungolo' => [3.8850, 11.5250], 'Etoudi' => [3.9080, 11.5300]],
            3 => ['Essos' => [3.8750, 11.5350], 'Omnisports' => [3.8850, 11.5350], 'Mfandena' => [3.8800, 11.5300],
                  'Ngousso' => [3.8950, 11.5400], 'Nkoldongo' => [3.8650, 11.5400], 'Mballa II' => [3.9000, 11.5250]],
            4 => ['Mvog-Mbi' => [3.8500, 11.5250], 'Mvog-Ada' => [3.8450, 11.5350], 'Mimboman' => [3.8500, 11.5450],
                  'Kondengui' => [3.8350, 11.5450], 'Ekounou' => [3.8200, 11.5500], 'Awae' => [3.8400, 11.5600]],
            5 => ['Nsam' => [3.8300, 11.5200], 'Mvan' => [3.8150, 11.5200], 'Odza' => [3.8100, 11.5450],
                  'Ahala' => [3.8000, 11.5100], 'Nkomo' => [3.8250, 11.5600], 'Nsimalen' => [3.7220, 11.5530]],
            6 => ['Biyem-Assi' => [3.8300, 11.4700], 'Mendong' => [3.8300, 11.4500], 'Etoug-Ebe' => [3.8500, 11.4700],
                  'Melen' => [3.8600, 11.4850], 'Ngoa-Ekelle' => [3.8600, 11.5000], 'Obili' => [3.8550, 11.4900],
                  'Simbock' => [3.8100, 11.4800], 'Nsimeyong' => [3.8300, 11.4900]],
            7 => ['Emana' => [3.9400, 11.5200], 'Olembé' => [3.9500, 11.5100], 'Nkolmesseng' => [3.9200, 11.5400],
                  'Nkolbisson' => [3.8700, 11.4300], 'Nkoabang' => [3.8600, 11.6000], 'Nkolfoulou' => [3.8800, 11.6100]],
        ]],
        'Bafoussam' => ['country' => 'CM', 'zones' => [
            1 => ['Centre Commercial' => [5.4781, 10.4176], 'Marché A' => [5.4790, 10.4190], 'Quartier Administratif' => [5.4830, 10.4230], 'Djemoun' => [5.4740, 10.4200]],
            2 => ['Tamdja' => [5.4700, 10.4100], 'Banengo' => [5.4650, 10.4250], 'Bamendzi' => [5.4850, 10.4100]],
            3 => ['Djeleng' => [5.4880, 10.4180], 'Ndiendam' => [5.4830, 10.4130], 'Famla' => [5.4700, 10.4300]],
            4 => ['Kamkop' => [5.4950, 10.4300], 'Tougang' => [5.4600, 10.4050], 'Koptchou' => [5.4550, 10.4150]],
            5 => ['Tyo-Ville' => [5.5050, 10.4050], 'Lafé' => [5.4500, 10.3950], 'Tchitchap' => [5.5100, 10.4250]],
        ]],
        'Bamenda' => ['country' => 'CM', 'zones' => [
            1 => ['Commercial Avenue' => [5.9600, 10.1500], 'City Chemist' => [5.9580, 10.1520], 'Food Market' => [5.9560, 10.1480], 'Ntarikon' => [5.9700, 10.1450]],
            2 => ['Mankon' => [5.9550, 10.1400], 'Azire' => [5.9650, 10.1400], 'Ntamulung' => [5.9500, 10.1350], 'Mulang' => [5.9450, 10.1450]],
            3 => ['Nkwen' => [5.9800, 10.1700], 'Mile 2 Nkwen' => [5.9750, 10.1620], 'Mile 4 Nkwen' => [5.9900, 10.1800], 'Bayelle' => [5.9850, 10.1650]],
            4 => ['Up Station' => [5.9400, 10.1750], 'Old Town' => [5.9450, 10.1600], 'GRA Bamenda' => [5.9420, 10.1700]],
            5 => ['Bambili' => [5.9950, 10.2400], 'Bambui' => [6.0100, 10.2600], 'Mile 8 Mankon' => [5.9300, 10.1200]],
        ]],
        'Garoua' => ['country' => 'CM', 'zones' => [
            1 => ['Centre Commercial' => [9.3014, 13.3977], 'Plateau' => [9.3050, 13.3900], 'Grand Marché' => [9.3000, 13.3950], 'Quartier Administratif' => [9.3080, 13.3850]],
            2 => ['Poumpoumré' => [9.3150, 13.4050], 'Bibémiré' => [9.3200, 13.4100], 'Foulbéré' => [9.3100, 13.4000]],
            3 => ['Yelwa' => [9.3300, 13.3700], 'Lopéré' => [9.3120, 13.3800], 'Marouaré' => [9.3060, 13.3750]],
            4 => ['Djamboutou' => [9.2850, 13.3600], 'Roumdé Adjia' => [9.2900, 13.4150], 'Kolléré' => [9.2950, 13.3900]],
            5 => ['Ouro Labbo' => [9.2700, 13.3500], 'Camp Chinois' => [9.3250, 13.3950], 'Souari' => [9.2800, 13.4050]],
        ]],
        'Maroua' => ['country' => 'CM', 'zones' => [
            1 => ['Domayo' => [10.5900, 14.3200], 'Centre Commercial' => [10.5956, 14.3247], 'Grand Marché' => [10.5930, 14.3260]],
            2 => ['Djarengol' => [10.6050, 14.3150], 'Founangué' => [10.6000, 14.3350], 'Djarengol Kaïgama' => [10.6100, 14.3100]],
            3 => ['Pitoaré' => [10.5850, 14.3400], 'Zokok' => [10.5750, 14.3150], 'Kakataré' => [10.5800, 14.3300]],
            4 => ['Doualaré' => [10.6000, 14.3050], 'Hardé' => [10.5700, 14.3450], 'Palar' => [10.6150, 14.3250]],
            5 => ['Makabaye' => [10.5600, 14.2950], 'Dougoy' => [10.6200, 14.3400], 'Mizao' => [10.5500, 14.3500]],
        ]],
        'Ngaoundéré' => ['country' => 'CM', 'zones' => [
            1 => ['Centre-ville' => [7.3277, 13.5847], 'Grand Marché' => [7.3250, 13.5830], 'Petit Marché' => [7.3300, 13.5880], 'Quartier Administratif' => [7.3350, 13.5800]],
            2 => ['Baladji' => [7.3400, 13.5750], 'Bamyanga' => [7.3200, 13.5700], 'Mbideng' => [7.3150, 13.5900]],
            3 => ['Sabongari' => [7.3350, 13.5950], 'Quartier Haoussa' => [7.3300, 13.5950], 'Madagascar' => [7.3180, 13.5950]],
            4 => ['Dang' => [7.3900, 13.5550], 'Norvégien' => [7.3450, 13.5680], 'Onaref' => [7.3100, 13.5750]],
            5 => ['Gada Mabanga' => [7.3050, 13.6050], 'Béka Matari' => [7.3550, 13.6100], 'Joli Soir' => [7.3450, 13.6000]],
        ]],
        'Bertoua' => ['country' => 'CM', 'zones' => [
            1 => ['Centre-ville' => [4.5774, 13.6846], 'Marché Central' => [4.5760, 13.6830], 'Quartier Administratif' => [4.5820, 13.6880]],
            2 => ['Nkolbikon 1' => [4.5650, 13.6750], 'Nkolbikon 2' => [4.5600, 13.6700], 'Mokolo' => [4.5700, 13.6900]],
            3 => ['Tigaza' => [4.5900, 13.6950], 'Madagascar' => [4.5850, 13.6750], 'Nyanganza' => [4.5950, 13.6880]],
            4 => ['Quartier Haoussa' => [4.5720, 13.6950], 'Enia' => [4.5680, 13.7000], 'Bodomo' => [4.5820, 13.6700]],
            5 => ['Mandjou' => [4.5500, 13.7700], 'Kpokolota' => [4.6000, 13.6650], 'Yademe' => [4.5550, 13.6600]],
        ]],
        'Limbé' => ['country' => 'CM', 'zones' => [
            1 => ['Down Beach' => [4.0180, 9.2120], 'Half Mile' => [4.0230, 9.2180], 'Church Street' => [4.0210, 9.2150]],
            2 => ['Mile 1' => [4.0270, 9.2230], 'Mile 2' => [4.0320, 9.2300], 'Unity Quarters' => [4.0290, 9.2260]],
            3 => ['Bota' => [4.0090, 9.1980], 'GRA Limbé' => [4.0150, 9.2050], 'Isokolo' => [4.0200, 9.2080]],
            4 => ['Mile 4' => [4.0420, 9.2420], 'Middle Farms' => [4.0350, 9.2200], 'Batoke' => [4.0500, 9.1500]],
        ]],
        'Buéa' => ['country' => 'CM', 'zones' => [
            1 => ['Buea Town' => [4.1550, 9.2310], 'Federal Quarters' => [4.1580, 9.2350], 'Clerks Quarters' => [4.1600, 9.2380]],
            2 => ['Molyko' => [4.1550, 9.2850], 'Checkpoint' => [4.1520, 9.2650], 'Sandpit' => [4.1500, 9.2750]],
            3 => ['Great Soppo' => [4.1480, 9.2450], 'Small Soppo' => [4.1450, 9.2550], 'Bonduma' => [4.1620, 9.2500]],
            4 => ['Mile 16' => [4.1200, 9.3050], 'Mile 17' => [4.1350, 9.2950], 'Muea' => [4.1650, 9.3100], 'Bomaka' => [4.1420, 9.2880]],
        ]],
        'Kribi' => ['country' => 'CM', 'zones' => [
            1 => ['Centre-ville' => [2.9400, 9.9100], 'Marché Central' => [2.9380, 9.9120], 'Petit Paris' => [2.9430, 9.9080]],
            2 => ['Dombé' => [2.9500, 9.9150], 'Mboa-Manga' => [2.9550, 9.9200], 'Mokolo' => [2.9460, 9.9180]],
            3 => ['Talla' => [2.9300, 9.9050], 'Mpangou' => [2.9250, 9.9100], 'Ngoyé' => [2.9350, 9.9200]],
            4 => ['Bwambé' => [2.9000, 9.8950], 'Afan-Mabé' => [2.9650, 9.9300], 'Mboro' => [2.9150, 9.9250]],
        ]],
        'Édéa' => ['country' => 'CM', 'zones' => [
            1 => ['Centre Commercial' => [3.8000, 10.1333], 'Marché Central' => [3.7980, 10.1350], 'Quartier Administratif' => [3.8050, 10.1300]],
            2 => ['Ekwalasimbi' => [3.7930, 10.1400], 'Bilalang' => [3.7880, 10.1280], 'Mbanda' => [3.8080, 10.1420]],
            3 => ['Pongo' => [3.8120, 10.1200], 'Ngwélé' => [3.7850, 10.1450], 'Bonandalé' => [3.8060, 10.1180]],
            4 => ['Sodiko' => [3.8200, 10.1500], 'Ekité' => [3.7750, 10.1200], 'Bonépoupa' => [3.8400, 10.1000]],
        ]],
        'Nkongsamba' => ['country' => 'CM', 'zones' => [
            1 => ['Centre-ville' => [4.9547, 9.9404], 'Marché Central' => [4.9530, 9.9420], 'Quartier Administratif' => [4.9580, 9.9380]],
            2 => ['Quartier Haoussa' => [4.9620, 9.9450], 'Nkongsamba I' => [4.9600, 9.9350], 'Manengolé' => [4.9900, 9.9200]],
            3 => ['Nkassa' => [4.9480, 9.9350], 'Nkongsamba II' => [4.9450, 9.9450], 'Nkongsamba III' => [4.9700, 9.9300]],
            4 => ['Ndoungué' => [4.9200, 9.9600], 'Baré' => [4.9100, 9.8900], 'Mbanga Pongo' => [4.9750, 9.9550]],
        ]],
        'Ebolowa' => ['country' => 'CM', 'zones' => [
            1 => ['Centre-ville' => [2.9000, 11.1500], 'Marché Central' => [2.8980, 11.1520], 'Quartier Administratif' => [2.9050, 11.1470]],
            2 => ['Angalé' => [2.9100, 11.1600], "Nko'ovos" => [2.8900, 11.1400], 'Nkoemvone' => [2.8700, 11.1700]],
            3 => ['Enongal' => [2.9200, 11.1350], 'Mekalat' => [2.8800, 11.1250], 'Abang' => [2.9300, 11.1700]],
        ]],
        'Sangmélima' => ['country' => 'CM', 'zones' => [
            1 => ['Centre-ville' => [2.9333, 11.9833], 'Marché Central' => [2.9315, 11.9850], 'Quartier Administratif' => [2.9370, 11.9800]],
            2 => ['Akon' => [2.9400, 11.9900], 'Nkolmeyang' => [2.9250, 11.9750], 'Mekomo' => [2.9450, 11.9700]],
            3 => ['Bity' => [2.9150, 11.9950], 'Ajap' => [2.9550, 11.9600], 'Mebada' => [2.9200, 12.0000]],
        ]],
        'Batouri' => ['country' => 'CM', 'zones' => [
            1 => ['Centre-ville' => [4.4333, 14.3667], 'Marché Central' => [4.4315, 14.3685], 'Quartier Administratif' => [4.4370, 14.3640]],
            2 => ['Quartier Haoussa' => [4.4400, 14.3720], 'Mokolo' => [4.4280, 14.3600], 'Njangané' => [4.4450, 14.3450]],
            3 => ['Kambélé' => [4.4500, 14.3900], 'Tikonjo' => [4.4200, 14.3500], 'Boubara' => [4.4150, 14.3800]],
        ]],
        'Yokadouma' => ['country' => 'CM', 'zones' => [
            1 => ['Centre-ville' => [3.5167, 15.0500], 'Marché Central' => [3.5150, 15.0520], 'Quartier Administratif' => [3.5200, 15.0470]],
            2 => ['Quartier Haoussa' => [3.5220, 15.0550], 'Mokolo' => [3.5100, 15.0450], 'Bordeaux' => [3.5250, 15.0420]],
            3 => ['Zoulabot' => [3.5300, 15.0700], 'Mboumo' => [3.5000, 15.0350], 'Gribé' => [3.4950, 15.0650]],
        ]],
        "N'Djaména" => ['country' => 'TD', 'zones' => [
            1 => ['Centre-ville' => [12.1067, 15.0444], 'Marché Central' => [12.1100, 15.0480], 'Klemat' => [12.1150, 15.0500]],
            2 => ['Moursal' => [12.1000, 15.0700], 'Chagoua' => [12.0900, 15.0800], 'Dembé' => [12.0950, 15.0650]],
            3 => ['Farcha' => [12.1400, 15.0200], 'Sabangali' => [12.0980, 15.0400], 'Gardolé' => [12.1120, 15.0420]],
            4 => ['Diguel' => [12.1300, 15.0750], 'Am-Riguébé' => [12.1200, 15.0600], 'Ardep-Djoumal' => [12.1250, 15.0550]],
            5 => ['Walia' => [12.0600, 15.0450], 'Atrone' => [12.0700, 15.0700], 'Ngueli' => [12.0400, 15.0350]],
        ]],
    ];

    public function run(): void
    {
        $solex = DelivererCompany::where('name', 'SOLEX')->first();

        if (!$solex) {
            $this->command?->warn('SOLEX est introuvable : lancez d\'abord DeliveryPartnersSeeder.');

            return;
        }

        $created = $completed = $untouched = 0;

        foreach (self::CITIES as $city => $definition) {
            $zones = $this->zonesOf($definition['zones']);
            $grid = DeliveryCityGrid::where('deliverer_company_id', $solex->id)->where('city', $city)->first();

            if (!$grid) {
                DeliveryCityGrid::create([
                    'deliverer_company_id' => $solex->id,
                    'city' => $city,
                    'country' => $definition['country'],
                    'zones' => $zones,
                    'vehicles' => $this->vehiclesOf($definition['zones']),
                    'agency_zone' => 1,
                    'asso_commission' => 0,
                    'is_active' => true,
                ]);
                $created++;
                $this->command?->line("  + {$city} : " . count($zones) . ' zones, ' . $this->quarterCount($zones) . ' quartiers');

                continue;
            }

            // Ville déjà présente : on ne remplit que les zones restées vides et la zone
            // d'agence manquante. Les prix et les quartiers saisis dans l'admin sont gardés.
            $seedZones = collect($zones)->keyBy('code');
            $filled = 0;
            $merged = collect($grid->zones)->map(function ($zone) use ($seedZones, &$filled) {
                if (DeliveryCityGrid::quartersOf($zone) !== []) {
                    return $zone;
                }
                $quarters = $seedZones[(int) ($zone['code'] ?? 0)]['quarters'] ?? [];
                $filled += count($quarters);
                $zone['quarters'] = $quarters;

                return $zone;
            })->all();

            if ($filled === 0 && $grid->agency_zone) {
                $untouched++;
                continue;
            }

            $grid->update([
                'zones' => $merged,
                'agency_zone' => $grid->agency_zone ?: 1,
                // Une grille sans quartier est inutilisable : elle s'active une fois remplie.
                'is_active' => $grid->is_active || $filled > 0,
            ]);
            $completed++;
            $this->command?->line("  ~ {$city} : {$filled} quartiers ajoutés aux zones vides");
        }

        // Zone de l'agence SOLEX de Douala (siège de Bali, zone 1) : sans elle, la livraison
        // à domicile après un trajet interurbain arrivant à Douala n'est pas proposée.
        $douala = DeliveryCityGrid::where('deliverer_company_id', $solex->id)->where('city', 'Douala')->first();
        if ($douala && !$douala->agency_zone) {
            $douala->update(['agency_zone' => 1]);
            $this->command?->line('  ~ Douala : zone de l\'agence fixée à la zone 1 (Bali)');
        }

        $this->command?->info("SOLEX urbain : {$created} ville(s) créée(s), {$completed} complétée(s), {$untouched} inchangée(s).");
        $this->command?->warn('Prix calqués sur Douala et quartiers positionnés approximativement : à ajuster dans l\'admin.');
    }

    /** @return array<int, array{code: int, label: string, quarters: array}> */
    private function zonesOf(array $definition): array
    {
        return collect($definition)->map(fn (array $quarters, int $code) => [
            'code' => $code,
            'label' => "Zone {$code}",
            'quarters' => collect($quarters)->map(fn (array $point, string $name) => [
                'name' => $name, 'lat' => $point[0], 'lng' => $point[1],
            ])->values()->all(),
        ])->values()->all();
    }

    /**
     * Véhicules et prix zone à zone : prix = base du véhicule × palier, le palier venant
     * de la distance entre les centres des deux zones (barème de Douala : ×1, ×1,5, ×2, ×2,5).
     */
    private function vehiclesOf(array $definition): array
    {
        $centers = collect($definition)->map(fn (array $quarters) => [
            collect($quarters)->avg(fn ($p) => $p[0]),
            collect($quarters)->avg(fn ($p) => $p[1]),
        ])->all();
        $codes = array_keys($definition);

        return collect(self::VEHICLES)->map(function (array $vehicle) use ($centers, $codes) {
            $prices = [];
            foreach ($codes as $from) {
                foreach ($codes as $to) {
                    if ($to < $from) {
                        continue;
                    }
                    $prices[$from . '-' . $to] = (int) round($vehicle['base'] * $this->step($centers, $from, $to));
                }
            }

            return [
                'code' => $vehicle['code'],
                'label' => $vehicle['label'],
                'max_weight_kg' => $vehicle['max_weight_kg'],
                'lead_time' => $vehicle['lead_time'],
                'prices' => $prices,
            ];
        })->all();
    }

    private function step(array $centers, int $from, int $to): float
    {
        if ($from === $to) {
            return 1.0;
        }
        $km = DeliveryCityGrid::distanceKm($centers[$from][0], $centers[$from][1], $centers[$to][0], $centers[$to][1]);

        return $km <= self::STEP_NEAR_KM ? 1.5 : ($km <= self::STEP_FAR_KM ? 2.0 : 2.5);
    }

    private function quarterCount(array $zones): int
    {
        return collect($zones)->sum(fn ($zone) => count($zone['quarters']));
    }
}
