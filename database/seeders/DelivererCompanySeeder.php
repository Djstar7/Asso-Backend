<?php

namespace Database\Seeders;

use App\Models\DelivererCompany;
use App\Models\DeliveryZone;
use App\Models\DeliveryPricelist;
use App\Models\DelivererSyncCode;
use Illuminate\Database\Seeder;

class DelivererCompanySeeder extends Seeder
{
    public function run(): void
    {
        $companies = [
            [
                'name' => 'Express Livraison Douala',
                'phone' => '+237 699 112 233',
                'email' => 'contact@express-douala.cm',
                'description' => 'Service de livraison express couvrant Douala et ses environs. Livraison le jour même pour les commandes avant 14h.',
                'zones' => [
                    [
                        'name' => 'Douala Centre',
                        'center_latitude' => 4.0511,
                        'center_longitude' => 9.7679,
                        'pricing_type' => 'fixed',
                        'pricing_data' => ['price' => 1500],
                    ],
                    [
                        'name' => 'Douala Périphérie',
                        'center_latitude' => 4.0833,
                        'center_longitude' => 9.7500,
                        'pricing_type' => 'weight_category',
                        'pricing_data' => [
                            'X-small' => 500,
                            '30 Deep' => 1000,
                            '50 Deep' => 1500,
                            '60 Deep' => 2000,
                            'Rainbow XL' => 3500,
                            'Pallet' => 8000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Yaoundé Flash Delivery',
                'phone' => '+237 677 445 566',
                'email' => 'info@yaounde-flash.cm',
                'description' => 'Livraison rapide dans la capitale. Réseau de livreurs moto pour une couverture optimale.',
                'zones' => [
                    [
                        'name' => 'Yaoundé Centre',
                        'center_latitude' => 3.8480,
                        'center_longitude' => 11.5021,
                        'pricing_type' => 'fixed',
                        'pricing_data' => ['price' => 1000],
                    ],
                    [
                        'name' => 'Yaoundé Banlieue',
                        'center_latitude' => 3.8667,
                        'center_longitude' => 11.5167,
                        'pricing_type' => 'volumetric_weight',
                        'pricing_data' => [
                            'ranges' => [
                                ['min' => 0, 'max' => 5, 'price' => 800],
                                ['min' => 5.01, 'max' => 15, 'price' => 1500],
                                ['min' => 15.01, 'max' => 30, 'price' => 2500],
                                ['min' => 30.01, 'max' => 100, 'price' => 5000],
                            ],
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Cotonou Express',
                'phone' => '+229 97 88 77 66',
                'email' => 'service@cotonou-express.bj',
                'description' => 'Le leader de la livraison au Bénin. Couverture complète de Cotonou et Porto-Novo.',
                'zones' => [
                    [
                        'name' => 'Cotonou Ville',
                        'center_latitude' => 6.3703,
                        'center_longitude' => 2.3912,
                        'pricing_type' => 'fixed',
                        'pricing_data' => ['price' => 1200],
                    ],
                    [
                        'name' => 'Cotonou - Calavi',
                        'center_latitude' => 6.4531,
                        'center_longitude' => 2.3481,
                        'pricing_type' => 'weight_category',
                        'pricing_data' => [
                            'X-small' => 400,
                            '30 Deep' => 800,
                            '50 Deep' => 1200,
                            '60 Deep' => 1800,
                            'Rainbow XL' => 3000,
                            'Pallet' => 7000,
                        ],
                    ],
                    [
                        'name' => 'Porto-Novo',
                        'center_latitude' => 6.4969,
                        'center_longitude' => 2.6289,
                        'pricing_type' => 'fixed',
                        'pricing_data' => ['price' => 2000],
                    ],
                ],
            ],
            [
                'name' => 'Sahel Transport Rapide',
                'phone' => '+227 90 11 22 33',
                'email' => 'contact@sahel-transport.ne',
                'description' => 'Transport et livraison dans la région sahélienne. Spécialiste des colis volumineux.',
                'zones' => [
                    [
                        'name' => 'Niamey Centre',
                        'center_latitude' => 13.5126,
                        'center_longitude' => 2.1128,
                        'pricing_type' => 'volumetric_weight',
                        'pricing_data' => [
                            'ranges' => [
                                ['min' => 0, 'max' => 10, 'price' => 1000],
                                ['min' => 10.01, 'max' => 25, 'price' => 2000],
                                ['min' => 25.01, 'max' => 50, 'price' => 3500],
                                ['min' => 50.01, 'max' => 200, 'price' => 7000],
                            ],
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Abidjan Livraison Pro',
                'phone' => '+225 07 88 99 00',
                'email' => 'pro@abidjan-livraison.ci',
                'description' => 'Service professionnel de livraison à Abidjan. Suivi en temps réel et assurance colis.',
                'zones' => [
                    [
                        'name' => 'Plateau - Cocody',
                        'center_latitude' => 5.3364,
                        'center_longitude' => -3.9527,
                        'pricing_type' => 'fixed',
                        'pricing_data' => ['price' => 2000],
                    ],
                    [
                        'name' => 'Yopougon',
                        'center_latitude' => 5.3275,
                        'center_longitude' => -4.0689,
                        'pricing_type' => 'weight_category',
                        'pricing_data' => [
                            'X-small' => 600,
                            '30 Deep' => 1200,
                            '50 Deep' => 1800,
                            '60 Deep' => 2500,
                            'Rainbow XL' => 4000,
                            'Pallet' => 9000,
                        ],
                    ],
                ],
            ],
        ];

        foreach ($companies as $companyData) {
            $zones = $companyData['zones'];
            unset($companyData['zones']);

            $company = DelivererCompany::create($companyData);

            foreach ($zones as $zoneData) {
                $pricingType = $zoneData['pricing_type'];
                $pricingData = $zoneData['pricing_data'];
                unset($zoneData['pricing_type'], $zoneData['pricing_data']);

                $zone = DeliveryZone::create(array_merge($zoneData, [
                    'deliverer_company_id' => $company->id,
                ]));

                DeliveryPricelist::create([
                    'delivery_zone_id' => $zone->id,
                    'pricing_type' => $pricingType,
                    'pricing_data' => $pricingData,
                ]);
            }

            // Generate a sync code for each company
            $syncCode = DelivererSyncCode::generateSyncCode();
            DelivererSyncCode::create([
                'user_id' => null,
                'company_id' => $company->id,
                'sync_code' => $syncCode,
                'sent_via' => 'email',
                'sent_at' => now(),
                'expires_at' => now()->addDays(30),
            ]);

            $this->command->info("  Created: {$company->name} ({$syncCode}) - " . count($zones) . " zone(s)");
        }

        $this->command->info('Deliverer companies seeded: ' . count($companies));
    }
}
