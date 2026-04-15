<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Shop;
use App\Models\Subcategory;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class OrderTestSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('=== OrderTestSeeder ===');

        // 1. Créer un client test avec du wallet
        $client = $this->createTestClient();

        // 2. Créer des produits pour les boutiques existantes
        $this->createProductsForExistingShops();

        $this->command->info('');
        $this->command->info('✅ Seeder terminé !');
        $this->command->info("   Client test: {$client->email} (FreeMoPay: {$client->freemopay_wallet_balance} FCFA)");
        $this->command->info('   Produits créés pour les boutiques existantes');
    }

    private function createTestClient(): User
    {
        $client = User::firstOrCreate(
            ['email' => 'client.test@asso.com'],
            [
                'first_name' => 'Client',
                'last_name' => 'Test',
                'phone' => '+237690000001',
                'password' => bcrypt('password'),
                'role' => 'client',
                'roles' => ['client'],
                'address' => 'Rue de la Joie, Akwa, Douala',
                'latitude' => 4.0435,
                'longitude' => 9.6966,
                'country' => 'Cameroun',
                'freemopay_wallet_balance' => 500000,
                'paypal_wallet_balance' => 100000,
                'locked_freemopay_balance' => 0,
                'locked_paypal_balance' => 0,
                'is_profile_complete' => true,
            ]
        );

        // S'assurer que le wallet est garni
        if ($client->freemopay_wallet_balance < 500000) {
            $client->update(['freemopay_wallet_balance' => 500000]);
        }

        $this->command->info("👤 Client: {$client->email} (wallet: {$client->freemopay_wallet_balance} FCFA)");

        return $client;
    }

    private function createProductsForExistingShops(): void
    {
        $shops = Shop::where('status', 'active')->with('user')->get();

        if ($shops->isEmpty()) {
            $this->command->warn('⚠️ Aucune boutique active trouvée');
            return;
        }

        $weightCategories = ['X-small', '30 Deep', '50 Deep', '60 Deep', 'Rainbow XL', 'Pallet'];

        $productsByCategory = [
            1 => [ // Électronique
                ['name' => 'Samsung Galaxy A54', 'price' => 185000, 'weight' => 'X-small'],
                ['name' => 'iPhone 13 Reconditionné', 'price' => 320000, 'weight' => 'X-small'],
                ['name' => 'Laptop HP 15 Pouces', 'price' => 450000, 'weight' => '30 Deep'],
                ['name' => 'Écouteurs Bluetooth JBL', 'price' => 25000, 'weight' => 'X-small'],
                ['name' => 'Chargeur Rapide USB-C', 'price' => 8000, 'weight' => 'X-small'],
                ['name' => 'Smart TV 43 Pouces', 'price' => 280000, 'weight' => '50 Deep'],
                ['name' => 'Tablette Samsung Tab A9', 'price' => 145000, 'weight' => 'X-small'],
            ],
            2 => [ // Mode & Vêtements
                ['name' => 'Robe Africaine Wax', 'price' => 35000, 'weight' => 'X-small'],
                ['name' => 'Costume Homme 3 Pièces', 'price' => 75000, 'weight' => '30 Deep'],
                ['name' => 'Chaussures Cuir Homme', 'price' => 45000, 'weight' => 'X-small'],
                ['name' => 'Sac à Main Cuir', 'price' => 28000, 'weight' => 'X-small'],
                ['name' => 'Ensemble Sport Nike', 'price' => 42000, 'weight' => 'X-small'],
            ],
            3 => [ // Maison & Jardin
                ['name' => 'Ventilateur 18 Pouces', 'price' => 35000, 'weight' => '50 Deep'],
                ['name' => 'Cuisinière à Gaz 4 Feux', 'price' => 195000, 'weight' => '60 Deep'],
                ['name' => 'Réfrigérateur 200L', 'price' => 320000, 'weight' => 'Rainbow XL'],
                ['name' => 'Machine à Laver 7kg', 'price' => 280000, 'weight' => 'Rainbow XL'],
                ['name' => 'Matelas Mousse 2 Places', 'price' => 85000, 'weight' => 'Pallet'],
                ['name' => 'Table Basse en Bois', 'price' => 55000, 'weight' => '60 Deep'],
            ],
            5 => [ // Beauté & Santé
                ['name' => 'Coffret Parfum Homme', 'price' => 22000, 'weight' => 'X-small'],
                ['name' => 'Huile de Coco Bio 500ml', 'price' => 5000, 'weight' => 'X-small'],
                ['name' => 'Kit Maquillage Complet', 'price' => 18000, 'weight' => 'X-small'],
            ],
        ];

        $placeholderImages = [
            'https://picsum.photos/seed/{slug}/600/600',
        ];

        $totalProducts = 0;

        foreach ($shops as $shop) {
            $this->command->info("🏪 Boutique: {$shop->name} (Vendeur: {$shop->user->first_name})");

            // Choisir 2-3 catégories aléatoires pour cette boutique
            $categoryIds = array_keys($productsByCategory);
            shuffle($categoryIds);
            $selectedCategories = array_slice($categoryIds, 0, rand(2, 3));

            foreach ($selectedCategories as $catId) {
                $products = $productsByCategory[$catId];
                $subcategory = Subcategory::where('category_id', $catId)->inRandomOrder()->first();

                foreach ($products as $productData) {
                    // Vérifier si le produit existe déjà pour cette boutique
                    $exists = Product::where('shop_id', $shop->id)
                        ->where('name', $productData['name'])
                        ->exists();

                    if ($exists) continue;

                    $slug = Str::slug($productData['name'] . '-' . $shop->id);

                    $product = Product::create([
                        'shop_id' => $shop->id,
                        'user_id' => $shop->user_id,
                        'category_id' => $catId,
                        'subcategory_id' => $subcategory?->id,
                        'name' => $productData['name'],
                        'slug' => $slug,
                        'description' => "Produit de qualité disponible chez {$shop->name}. Livraison possible dans votre ville.",
                        'price' => $productData['price'],
                        'price_type' => 'fixed',
                        'type' => 'article',
                        'stock' => rand(5, 50),
                        'weight_category' => $productData['weight'],
                        'status' => 'active',
                    ]);

                    // Créer 2 images placeholder
                    for ($i = 0; $i < 2; $i++) {
                        ProductImage::create([
                            'product_id' => $product->id,
                            'image_path' => "https://picsum.photos/seed/{$slug}-{$i}/600/600",
                            'is_primary' => $i === 0,
                            'order' => $i,
                        ]);
                    }

                    $totalProducts++;
                }
            }
        }

        $this->command->info("📦 {$totalProducts} produits créés au total");
    }
}
