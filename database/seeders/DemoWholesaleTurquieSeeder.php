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
 * Démo du module GROS — ASSO TURQUIE : un pays d'import, un vendeur, un produit
 * « gros » (huile de tournesol) avec paliers + cota, et des options d'expédition.
 *
 *   php artisan db:seed --class=DemoWholesaleTurquieSeeder
 */
class DemoWholesaleTurquieSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            $country = ImportCountry::updateOrCreate(
                ['code' => 'TR'],
                ['name' => 'ASSO Turquie', 'flag' => '🇹🇷', 'is_active' => true, 'sort_order' => 1]
            );

            $vendeur = User::firstOrCreate(
                ['email' => 'grossiste.turquie@asso.test'],
                [
                    'first_name' => 'Grossiste', 'last_name' => 'Turquie',
                    'password' => 'Password123!', 'role' => 'vendeur', 'roles' => ['vendeur'],
                    'phone' => '+237690000303', 'country' => 'Turquie', 'is_profile_complete' => true,
                ]
            );

            $categoryId = Category::query()->value('id') ?? Category::create(['name' => 'Général'])->id;

            $product = Product::updateOrCreate(
                ['user_id' => $vendeur->id, 'name' => 'Huile de tournesol 🌻'],
                [
                    'category_id' => $categoryId,
                    'description' => 'Huile de tournesol en gros, importée de Turquie. Qualité irréprochable.',
                    'price' => 10524, 'currency' => 'XAF', 'price_type' => 'fixed', 'type' => 'article',
                    'origin_country' => 'TR', 'is_wholesale' => true, 'stock' => 100000,
                    'weight_category' => 'Pallet', 'status' => 'active',
                ]
            );

            $tiers = [
                ['label' => 'Bidon 1L — pack de 12', 'unit_price' => 10524, 'min_quantity' => 100, 'pack_size' => 12, 'sort_order' => 1],
                ['label' => 'Bidon 5L — pack de 5', 'unit_price' => 13940, 'min_quantity' => 50, 'pack_size' => 5, 'sort_order' => 2],
                ['label' => 'Bidon 20L', 'unit_price' => 15600, 'min_quantity' => 50, 'pack_size' => 1, 'sort_order' => 3],
            ];
            foreach ($tiers as $t) {
                ProductPriceTier::updateOrCreate(
                    ['product_id' => $product->id, 'label' => $t['label']],
                    array_merge($t, ['currency' => 'XAF', 'is_active' => true])
                );
            }

            $shipping = [
                ['mode' => 'air', 'rate_type' => 'per_kg', 'rate_amount' => 4000, 'lead_time_days' => 9,
                 'expedition_note' => 'Commande du 11 au 15, arrivée le 20', 'sort_order' => 1],
                ['mode' => 'sea', 'rate_type' => 'per_kg', 'rate_amount' => 1500, 'lead_time_days' => 45,
                 'expedition_note' => 'Commande du 11 au 16, arrivée ~25 du mois suivant', 'sort_order' => 2],
                ['mode' => 'express', 'rate_type' => 'flat', 'rate_amount' => 250000, 'lead_time_days' => 3,
                 'expedition_note' => 'Expédition express en 3 jours', 'sort_order' => 3],
            ];
            foreach ($shipping as $s) {
                ImportShippingOption::updateOrCreate(
                    ['country_code' => 'TR', 'mode' => $s['mode']],
                    array_merge($s, ['country_code' => 'TR', 'currency' => 'XAF', 'is_active' => true,
                        'destinations' => ['Afrique', 'Europe', 'Canada', 'USA']])
                );
            }

            $this->command?->info('--- Démo GROS ASSO Turquie créée ---');
            $this->command?->info("Pays: {$country->name} (TR)");
            $this->command?->info("Produit gros: {$product->name} (id {$product->id}), 3 paliers");
            $this->command?->info('Expéditions: Avion/Bateau/Express');
        });
    }
}
