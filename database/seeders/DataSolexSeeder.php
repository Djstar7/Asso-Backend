<?php

namespace Database\Seeders;

use App\Models\DelivererCompany;
use App\Models\DeliveryRoute;
use Illuminate\Database\Seeder;

/**
 * SOLEX — le reste du réseau d'agences, en complément de DeliveryPartnersSeeder.
 *
 * DeliveryPartnersSeeder pose la grille du propriétaire (Douala ↔ Yaoundé, Bertoua,
 * Bafoussam, Ngaoundéré, Garoua, Maroua, Nkongsamba, Limbé) ; cette seeder ajoute
 * seulement les agences SOLEX qui y manquent, d'après la liste officielle publiée par
 * SOLEX (solextgv.com/contact.html) : Édéa, Buéa, Kribi, Bamenda, Ebolowa, Sangmélima,
 * Batouri, Yokadouma, et le point de relais du Tchad (N'Djaména).
 *
 * SOLEX ne publie aucun tarif en ligne : les prix ci-dessous sont ESTIMÉS à partir de la
 * grille du propriétaire et de la distance routière depuis Douala. À confirmer avec
 * SOLEX, puis à corriger dans l'admin — une relance de la seeder ne réécrit jamais un
 * trajet déjà en place.
 *
 * Rien d'autre n'est touché : ni la configuration, ni la grille urbaine de Douala, ni
 * les trajets, zones et transporteurs déjà enregistrés.
 */
class DataSolexSeeder extends Seeder
{
    /**
     * Agences SOLEX au départ de Douala (et vice versa), prix en FCFA hors taxe.
     *
     * Ville => [plis 0–2 kg, colis ≤ 10 kg, kg supplémentaire, délai de route, pays]
     */
    private const SOLEX_FROM_DOUALA = [
        'Édéa' => [2500, 3500, 100, '24 h', 'CM'],
        'Buéa' => [2500, 3500, 100, '24 h', 'CM'],
        'Kribi' => [2500, 4000, 150, '24 h', 'CM'],
        'Bamenda' => [3000, 4500, 150, '24 h', 'CM'],
        'Ebolowa' => [3000, 4500, 150, '48 h', 'CM'],
        'Sangmélima' => [3500, 5000, 150, '48 h', 'CM'],
        'Batouri' => [4000, 6000, 200, '72 h', 'CM'],
        'Yokadouma' => [4500, 7000, 200, '72 h', 'CM'],
        // Relais Cameroun ↔ Tchad, par Garoua et Kousséri (passage de frontière).
        "N'Djaména" => [7500, 15000, 600, '5 à 7 jours', 'TD'],
    ];

    public function run(): void
    {
        $solex = DelivererCompany::where('name', 'SOLEX')->first();

        if (!$solex) {
            $this->command?->warn('SOLEX est introuvable : lancez d\'abord DeliveryPartnersSeeder.');

            return;
        }

        $created = 0;

        foreach (self::SOLEX_FROM_DOUALA as $city => [$plis, $colis, $extraKg, $leadTime, $country]) {
            $route = DeliveryRoute::firstOrCreate(
                [
                    'deliverer_company_id' => $solex->id,
                    'origin_country' => 'CM',
                    'origin_city' => 'Douala',
                    'destination_country' => $country,
                    'destination_city' => $city,
                ],
                [
                    'bidirectional' => true,
                    'pricing_data' => [
                        'ranges' => [
                            ['min' => 0, 'max' => 2, 'price' => $plis, 'label' => 'Plis & paquets'],
                            ['min' => 2, 'max' => 10, 'price' => $colis, 'label' => 'Colis'],
                        ],
                        'extra_per_kg' => $extraKg,
                    ],
                    'asso_commission' => 0,
                    'lead_time' => $leadTime,
                    'is_active' => true,
                ]
            );

            $created += $route->wasRecentlyCreated ? 1 : 0;
        }

        $this->command?->info("SOLEX : {$created} agence(s) ajoutée(s), "
            . (count(self::SOLEX_FROM_DOUALA) - $created) . ' déjà en place.');
        $this->command?->warn('Tarifs estimés : à confirmer avec SOLEX puis à corriger dans l\'admin.');
    }
}
