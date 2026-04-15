<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use App\Models\Shop;
use App\Models\Category;
use App\Models\Subcategory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class ProductSeeder extends Seeder
{
    public function run(): void
    {
        // Ensure we have shops — create some if needed
        if (Shop::count() === 0) {
            $this->createShops();
        }

        $shops = Shop::all();
        if ($shops->isEmpty()) {
            $this->command->warn('No shops found, skipping product seeding.');
            return;
        }

        $products = [
            // Électronique (cat 1)
            ['category_id' => 1, 'subcategory_id' => 1, 'name' => 'Laptop HP ProBook 450 G10', 'price' => 450000, 'price_type' => 'fixed', 'type' => 'article', 'stock' => 8, 'weight_category' => '50 Deep', 'description' => 'Ordinateur portable professionnel, Intel Core i5, 8Go RAM, 256Go SSD, écran 15.6 pouces.'],
            ['category_id' => 1, 'subcategory_id' => 2, 'name' => 'Samsung Galaxy A54 5G', 'price' => 185000, 'price_type' => 'fixed', 'type' => 'article', 'stock' => 25, 'weight_category' => 'X-small', 'description' => 'Smartphone 5G, écran 6.4" Super AMOLED, 128Go, triple caméra 50MP.'],
            ['category_id' => 1, 'subcategory_id' => 7, 'name' => 'Écouteurs Bluetooth JBL Tune 520BT', 'price' => 28000, 'price_type' => 'fixed', 'type' => 'article', 'stock' => 40, 'weight_category' => 'X-small', 'description' => 'Casque sans fil Bluetooth 5.3, autonomie 57h, pliable, son JBL Pure Bass.'],
            ['category_id' => 1, 'subcategory_id' => 5, 'name' => 'Chargeur rapide USB-C 65W', 'price' => 12000, 'price_type' => 'fixed', 'type' => 'article', 'stock' => 100, 'weight_category' => 'X-small', 'description' => 'Chargeur GaN compact, compatible téléphone et laptop, charge rapide.'],

            // Mode (cat 2)
            ['category_id' => 2, 'subcategory_id' => 8, 'name' => 'Chemise en pagne africain homme', 'price' => 15000, 'price_type' => 'fixed', 'type' => 'article', 'stock' => 30, 'weight_category' => 'X-small', 'description' => 'Chemise manches courtes en tissu wax authentique, coupe moderne.'],
            ['category_id' => 2, 'subcategory_id' => 9, 'name' => 'Robe longue en wax multicolore', 'min_price' => 18000, 'max_price' => 35000, 'price_type' => 'variable', 'type' => 'article', 'stock' => 15, 'weight_category' => '30 Deep', 'description' => 'Robe longue élégante en tissu wax, plusieurs tailles et motifs disponibles.'],
            ['category_id' => 2, 'subcategory_id' => 11, 'name' => 'Sandales en cuir artisanal', 'price' => 22000, 'price_type' => 'fixed', 'type' => 'article', 'stock' => 20, 'weight_category' => '30 Deep', 'description' => 'Sandales faites main en cuir véritable, confortables et durables.'],

            // Maison (cat 3)
            ['category_id' => 3, 'subcategory_id' => 15, 'name' => 'Table basse en bois d\'ébène', 'price' => 95000, 'price_type' => 'fixed', 'type' => 'article', 'stock' => 5, 'weight_category' => 'Pallet', 'description' => 'Table basse sculptée à la main en bois d\'ébène du Cameroun.'],
            ['category_id' => 3, 'subcategory_id' => 17, 'name' => 'Set de casseroles antiadhésives 5 pièces', 'price' => 38000, 'price_type' => 'fixed', 'type' => 'article', 'stock' => 12, 'weight_category' => '60 Deep', 'description' => 'Lot de 5 casseroles avec revêtement antiadhésif, compatible tous feux.'],

            // Sports (cat 4)
            ['category_id' => 4, 'subcategory_id' => 22, 'name' => 'Haltères ajustables 2x10kg', 'price' => 35000, 'price_type' => 'fixed', 'type' => 'article', 'stock' => 10, 'weight_category' => 'Rainbow XL', 'description' => 'Paire d\'haltères ajustables de 2 à 10kg chacun, caoutchouc anti-bruit.'],
            ['category_id' => 4, 'subcategory_id' => 23, 'name' => 'Ballon de football Adidas Tiro League', 'price' => 18000, 'price_type' => 'fixed', 'type' => 'article', 'stock' => 50, 'weight_category' => '30 Deep', 'description' => 'Ballon d\'entraînement taille 5, cousu machine, haute durabilité.'],

            // Beauté (cat 5)
            ['category_id' => 5, 'subcategory_id' => 29, 'name' => 'Crème éclaircissante au karité bio', 'price' => 8500, 'price_type' => 'fixed', 'type' => 'article', 'stock' => 60, 'weight_category' => 'X-small', 'description' => 'Crème hydratante naturelle au beurre de karité, sans hydroquinone, 200ml.'],
            ['category_id' => 5, 'subcategory_id' => 32, 'name' => 'Huile de coco vierge pour cheveux 500ml', 'price' => 5500, 'price_type' => 'fixed', 'type' => 'article', 'stock' => 80, 'weight_category' => 'X-small', 'description' => 'Huile de coco pressée à froid, 100% naturelle, nourrit et fait briller les cheveux.'],

            // Alimentation (cat 8)
            ['category_id' => 8, 'subcategory_id' => 49, 'name' => 'Pack épices africaines 10 variétés', 'price' => 12000, 'price_type' => 'fixed', 'type' => 'article', 'stock' => 35, 'weight_category' => '30 Deep', 'description' => 'Coffret de 10 épices : piment camerounais, poivre de Penja, gingembre, ail, etc.'],
            ['category_id' => 8, 'subcategory_id' => 53, 'name' => 'Café moulu du Mont Cameroun 500g', 'price' => 6000, 'price_type' => 'fixed', 'type' => 'article', 'stock' => 45, 'weight_category' => 'X-small', 'description' => 'Café 100% arabica, torréfaction artisanale, arôme intense et corsé.'],

            // Services (pas de livraison physique, X-small par défaut)
            ['category_id' => 1, 'subcategory_id' => 5, 'name' => 'Réparation écran smartphone', 'min_price' => 15000, 'max_price' => 45000, 'price_type' => 'variable', 'type' => 'service', 'stock' => 999, 'weight_category' => 'X-small', 'description' => 'Remplacement écran pour toutes marques : Samsung, iPhone, Huawei, Xiaomi. Prix selon modèle.'],
            ['category_id' => 3, 'subcategory_id' => 20, 'name' => 'Installation climatiseur split', 'price' => 25000, 'price_type' => 'fixed', 'type' => 'service', 'stock' => 999, 'weight_category' => 'X-small', 'description' => 'Installation complète d\'un climatiseur split, mise en service et test inclus.'],

            // Automobile (cat 9)
            ['category_id' => 9, 'subcategory_id' => 56, 'name' => 'Housse siège auto universelle', 'price' => 22000, 'price_type' => 'fixed', 'type' => 'article', 'stock' => 18, 'weight_category' => '50 Deep', 'description' => 'Jeu complet de housses de siège auto, tissu respirant, compatible la plupart des véhicules.'],

            // Jouets (cat 7)
            ['category_id' => 7, 'subcategory_id' => 48, 'name' => 'Kit éducatif solaire pour enfants', 'price' => 15000, 'price_type' => 'fixed', 'type' => 'article', 'stock' => 25, 'weight_category' => '30 Deep', 'description' => 'Kit de construction de robots solaires 12-en-1, éducatif STEM, 8+ ans.'],
        ];

        $shopIds = $shops->pluck('id')->toArray();

        foreach ($products as $productData) {
            $shop = Shop::find($shopIds[array_rand($shopIds)]);

            Product::create(array_merge($productData, [
                'shop_id' => $shop->id,
                'user_id' => $shop->user_id,
                'status' => 'active',
            ]));
        }

        $this->command->info('Products seeded: ' . count($products));
    }

    private function createShops(): void
    {
        $sellers = User::where('role', 'vendeur')->limit(3)->get();

        if ($sellers->isEmpty()) {
            $this->command->warn('No seller users found, cannot create shops.');
            return;
        }

        $shopData = [
            ['name' => 'TechStore Douala', 'description' => 'Boutique high-tech, smartphones, laptops et accessoires.', 'address' => 'Rue des Palmiers, Akwa, Douala', 'latitude' => 4.0483, 'longitude' => 9.7043],
            ['name' => 'Mode Africaine by Amina', 'description' => 'Vêtements et accessoires en tissu africain authentique.', 'address' => 'Marché Mokolo, Yaoundé', 'latitude' => 3.8667, 'longitude' => 11.5167],
            ['name' => 'Épicerie du Sahel', 'description' => 'Produits alimentaires locaux et épices d\'Afrique de l\'Ouest.', 'address' => 'Avenue Charles de Gaulle, Cotonou', 'latitude' => 6.3703, 'longitude' => 2.3912],
        ];

        foreach ($sellers as $i => $seller) {
            if (!isset($shopData[$i])) break;

            $data = $shopData[$i];
            Shop::create([
                'user_id' => $seller->id,
                'name' => $data['name'],
                'slug' => Str::slug($data['name']),
                'description' => $data['description'],
                'address' => $data['address'],
                'latitude' => $data['latitude'],
                'longitude' => $data['longitude'],
                'status' => 'active',
                'verified_at' => now(),
            ]);
        }

        $this->command->info('  Created ' . min(count($sellers), count($shopData)) . ' shops for product seeding.');
    }
}
