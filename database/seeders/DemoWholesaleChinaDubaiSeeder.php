<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\ImportCountry;
use App\Models\ImportShippingOption;
use App\Models\Product;
use App\Models\ProductPriceTier;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Démo du module GROS pour ASSO CHINA (CN) et ASSO DUBAÏ (AE) : vendeurs grossistes,
 * produits « gros » avec paliers (cota) et options d'expédition par pays.
 *
 *   php artisan db:seed --class=DemoWholesaleChinaDubaiSeeder
 */
class DemoWholesaleChinaDubaiSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            $categoryId = Category::query()->value('id') ?? Category::create(['name' => 'Général'])->id;

            $this->seedCountry(
                code: 'CN', name: 'Chine', flag: '🇨🇳', sort: 2,
                vendorEmail: 'grossiste.chine@asso.test', vendorPhone: '+237690000404', vendorCountry: 'Chine',
                categoryId: $categoryId,
                products: [
                    [
                        'name' => 'Chargeur rapide USB-C 20W',
                        'description' => 'Chargeur rapide USB-C, import Chine. Qualité contrôlée.',
                        'tiers' => [
                            ['label' => 'Pack de 50', 'unit_price' => 45000, 'min_quantity' => 20, 'pack_size' => 50],
                            ['label' => 'Pack de 100', 'unit_price' => 85000, 'min_quantity' => 10, 'pack_size' => 100],
                            ['label' => 'Carton de 500', 'unit_price' => 400000, 'min_quantity' => 5, 'pack_size' => 500],
                        ],
                    ],
                    [
                        'name' => 'Écouteurs Bluetooth TWS',
                        'description' => 'Écouteurs sans fil TWS en gros, import Chine.',
                        'tiers' => [
                            ['label' => 'Pack de 20', 'unit_price' => 60000, 'min_quantity' => 10, 'pack_size' => 20],
                            ['label' => 'Carton de 100', 'unit_price' => 280000, 'min_quantity' => 5, 'pack_size' => 100],
                        ],
                    ],
                ],
                shipping: [
                    ['mode' => 'air', 'rate_type' => 'per_kg', 'rate_amount' => 4000, 'lead_time_days' => 9, 'expedition_note' => 'Départ hebdomadaire, ~9 jours', 'sort_order' => 1],
                    ['mode' => 'sea', 'rate_type' => 'per_kg', 'rate_amount' => 1500, 'lead_time_days' => 45, 'expedition_note' => 'Groupage, ~45 jours', 'sort_order' => 2],
                    ['mode' => 'express', 'rate_type' => 'flat', 'rate_amount' => 300000, 'lead_time_days' => 3, 'expedition_note' => 'Express 3 jours', 'sort_order' => 3],
                ],
            );

            $this->seedCountry(
                code: 'AE', name: 'Dubaï', flag: '🇦🇪', sort: 3,
                vendorEmail: 'grossiste.dubai@asso.test', vendorPhone: '+237690000505', vendorCountry: 'Émirats arabes unis',
                categoryId: $categoryId,
                products: [
                    [
                        'name' => 'Parfum Oud 100ml',
                        'description' => 'Parfum Oud premium, import Dubaï.',
                        'tiers' => [
                            ['label' => 'Pack de 12', 'unit_price' => 84000, 'min_quantity' => 10, 'pack_size' => 12],
                            ['label' => 'Pack de 24', 'unit_price' => 156000, 'min_quantity' => 5, 'pack_size' => 24],
                        ],
                    ],
                    [
                        'name' => 'Montre homme acier',
                        'description' => 'Montres homme en acier, lot en gros, import Dubaï.',
                        'tiers' => [
                            ['label' => 'Pack de 10', 'unit_price' => 120000, 'min_quantity' => 5, 'pack_size' => 10],
                        ],
                    ],
                ],
                shipping: [
                    ['mode' => 'air', 'rate_type' => 'per_kg', 'rate_amount' => 4500, 'lead_time_days' => 7, 'expedition_note' => 'Vols réguliers, ~7 jours', 'sort_order' => 1],
                    ['mode' => 'sea', 'rate_type' => 'per_kg', 'rate_amount' => 1600, 'lead_time_days' => 40, 'expedition_note' => 'Groupage maritime, ~40 jours', 'sort_order' => 2],
                    ['mode' => 'express', 'rate_type' => 'flat', 'rate_amount' => 350000, 'lead_time_days' => 3, 'expedition_note' => 'Express 3 jours', 'sort_order' => 3],
                ],
            );

            $this->command?->info('--- Démo GROS Chine + Dubaï créée ---');
        });
    }

    private function seedCountry(
        string $code, string $name, string $flag, int $sort,
        string $vendorEmail, string $vendorPhone, string $vendorCountry,
        int $categoryId, array $products, array $shipping
    ): void {
        ImportCountry::updateOrCreate(
            ['code' => $code],
            ['name' => $name, 'flag' => $flag, 'is_active' => true, 'sort_order' => $sort]
        );

        $vendeur = User::firstOrCreate(
            ['email' => $vendorEmail],
            [
                'first_name' => 'Grossiste', 'last_name' => $name,
                'password' => 'Password123!', 'role' => 'vendeur', 'roles' => ['vendeur'],
                'phone' => $vendorPhone, 'country' => $vendorCountry, 'is_profile_complete' => true,
            ]
        );

        foreach ($products as $pData) {
            $entryPrice = $pData['tiers'][0]['unit_price'] ?? 0;
            $product = Product::updateOrCreate(
                ['user_id' => $vendeur->id, 'name' => $pData['name']],
                [
                    'category_id' => $categoryId,
                    'description' => $pData['description'],
                    'price' => $entryPrice, 'currency' => 'XAF', 'price_type' => 'fixed', 'type' => 'article',
                    'origin_country' => $code, 'is_wholesale' => true, 'stock' => 100000,
                    'weight_category' => 'Pallet', 'status' => 'active',
                ]
            );

            foreach ($pData['tiers'] as $i => $t) {
                ProductPriceTier::updateOrCreate(
                    ['product_id' => $product->id, 'label' => $t['label']],
                    array_merge($t, ['currency' => 'XAF', 'is_active' => true, 'sort_order' => $i + 1])
                );
            }
        }

        foreach ($shipping as $s) {
            ImportShippingOption::updateOrCreate(
                ['country_code' => $code, 'mode' => $s['mode']],
                array_merge($s, ['country_code' => $code, 'currency' => 'XAF', 'is_active' => true,
                    'destinations' => ['Afrique', 'Europe', 'Canada', 'USA']])
            );
        }
    }
}
