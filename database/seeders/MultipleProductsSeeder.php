<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class MultipleProductsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // User and Shop IDs from existing data
        $userId = 21;
        $shopId = 10;

        $products = [
            // Electronics
            [
                'category_id' => 1,
                'subcategory_id' => 2,
                'name' => 'iPhone 15 Pro Max',
                'description' => 'Dernier smartphone Apple avec puce A17 Pro, écran Super Retina XDR de 6,7 pouces, appareil photo 48MP et batterie longue durée.',
                'price' => 850000,
                'stock' => 25,
                'weight_category' => 'X-small',
                'latitude' => 3.8480,
                'longitude' => 11.5021,
                'images' => [
                    'https://images.unsplash.com/photo-1678652197950-ef3c90873e1c?w=800',
                    'https://images.unsplash.com/photo-1678911820864-e2c567c655d7?w=800',
                ]
            ],
            [
                'category_id' => 1,
                'subcategory_id' => 1,
                'name' => 'MacBook Air M3 15 pouces',
                'description' => 'Ordinateur portable ultraléger avec puce M3, écran Liquid Retina de 15 pouces, 16Go RAM et 512Go SSD. Autonomie jusqu\'à 18 heures.',
                'price' => 1200000,
                'stock' => 15,
                'weight_category' => '30 Deep',
                'latitude' => 3.8525,
                'longitude' => 11.5085,
                'images' => [
                    'https://images.unsplash.com/photo-1517336714731-489689fd1ca8?w=800',
                    'https://images.unsplash.com/photo-1611186871348-b1ce696e52c9?w=800',
                ]
            ],
            [
                'category_id' => 1,
                'subcategory_id' => 7,
                'name' => 'AirPods Pro 2',
                'description' => 'Écouteurs sans fil avec réduction active du bruit, son spatial personnalisé et étui de charge USB-C. Autonomie jusqu\'à 30 heures.',
                'price' => 165000,
                'stock' => 40,
                'weight_category' => 'X-small',
                'latitude' => 3.8435,
                'longitude' => 11.4967,
                'images' => [
                    'https://images.unsplash.com/photo-1606841837239-c5a1a4a07af7?w=800',
                    'https://images.unsplash.com/photo-1625308278574-b1e7d4ce9bd2?w=800',
                ]
            ],

            // Fashion
            [
                'category_id' => 2,
                'subcategory_id' => 8,
                'name' => 'Costume Homme Élégant',
                'description' => 'Costume 2 pièces en laine mélangée, coupe slim fit. Parfait pour les occasions formelles et professionnelles. Disponible en plusieurs tailles.',
                'price' => 125000,
                'stock' => 30,
                'weight_category' => '30 Deep',
                'latitude' => 3.8590,
                'longitude' => 11.5142,
                'images' => [
                    'https://images.unsplash.com/photo-1593030103066-0093718efeb9?w=800',
                    'https://images.unsplash.com/photo-1594938298603-c8148c4dae35?w=800',
                ]
            ],
            [
                'category_id' => 2,
                'subcategory_id' => 9,
                'name' => 'Robe Cocktail Femme',
                'description' => 'Robe élégante en satin, coupe ajustée avec détails brodés. Idéale pour soirées et événements spéciaux. Tissu respirant et confortable.',
                'price' => 85000,
                'stock' => 35,
                'weight_category' => 'X-small',
                'latitude' => 3.8467,
                'longitude' => 11.5098,
                'images' => [
                    'https://images.unsplash.com/photo-1566174053879-31528523f8ae?w=800',
                    'https://images.unsplash.com/photo-1595777457583-95e059d581b8?w=800',
                ]
            ],
            [
                'category_id' => 2,
                'subcategory_id' => 11,
                'name' => 'Nike Air Max 270',
                'description' => 'Baskets sport avec unité Air visible, design moderne et confort optimal. Idéales pour le quotidien et le sport. Semelle amortissante.',
                'price' => 95000,
                'stock' => 50,
                'weight_category' => '30 Deep',
                'latitude' => 3.8512,
                'longitude' => 11.5034,
                'images' => [
                    'https://images.unsplash.com/photo-1542291026-7eec264c27ff?w=800',
                    'https://images.unsplash.com/photo-1606107557195-0e29a4b5b4aa?w=800',
                ]
            ],

            // Home & Garden
            [
                'category_id' => 3,
                'subcategory_id' => 15,
                'name' => 'Canapé 3 Places Moderne',
                'description' => 'Canapé confortable en tissu gris, structure en bois massif avec pieds chromés. Design scandinave moderne. Dimensions: 220x90x85cm.',
                'price' => 350000,
                'stock' => 12,
                'weight_category' => 'Pallet',
                'latitude' => 3.8558,
                'longitude' => 11.5115,
                'images' => [
                    'https://images.unsplash.com/photo-1555041469-a586c61ea9bc?w=800',
                    'https://images.unsplash.com/photo-1586023492125-27b2c045efd7?w=800',
                ]
            ],
            [
                'category_id' => 3,
                'subcategory_id' => 17,
                'name' => 'Ensemble Vaisselle 24 Pièces',
                'description' => 'Service de table complet en porcelaine blanche avec motifs dorés. Comprend assiettes, bols et tasses. Passe au lave-vaisselle.',
                'price' => 65000,
                'stock' => 25,
                'weight_category' => '60 Deep',
                'latitude' => 3.8423,
                'longitude' => 11.4985,
                'images' => [
                    'https://images.unsplash.com/photo-1584990347449-39b4aa8ece3c?w=800',
                    'https://images.unsplash.com/photo-1610701596007-11502861dcfa?w=800',
                ]
            ],

            // Sports
            [
                'category_id' => 4,
                'subcategory_id' => 22,
                'name' => 'Kit Haltères Ajustables 20kg',
                'description' => 'Set d\'haltères réglables avec plaques de poids, parfait pour l\'entraînement à domicile. Poignées antidérapantes et système de verrouillage sécurisé.',
                'price' => 75000,
                'stock' => 20,
                'weight_category' => '60 Deep',
                'latitude' => 3.8539,
                'longitude' => 11.5067,
                'images' => [
                    'https://images.unsplash.com/photo-1517836357463-d25dfeac3438?w=800',
                    'https://images.unsplash.com/photo-1623874514711-0f321325f318?w=800',
                ]
            ],
            [
                'category_id' => 4,
                'subcategory_id' => 25,
                'name' => 'VTT 27.5 pouces Professionnel',
                'description' => 'Vélo tout terrain avec cadre aluminium, suspension avant, freins à disque hydrauliques et 21 vitesses Shimano. Roues 27.5 pouces.',
                'price' => 285000,
                'stock' => 10,
                'weight_category' => 'Rainbow XL',
                'latitude' => 3.8475,
                'longitude' => 11.4952,
                'images' => [
                    'https://images.unsplash.com/photo-1576435728678-68d0fbf94e91?w=800',
                    'https://images.unsplash.com/photo-1532298229144-0ec0c57515c7?w=800',
                ]
            ],

            // Beauty & Health
            [
                'category_id' => 5,
                'subcategory_id' => 29,
                'name' => 'Coffret Soins Visage Premium',
                'description' => 'Ensemble complet de soins du visage avec sérum, crème hydratante, nettoyant et masque. Formules enrichies en vitamines et acide hyaluronique.',
                'price' => 55000,
                'stock' => 45,
                'weight_category' => 'X-small',
                'latitude' => 3.8598,
                'longitude' => 11.5128,
                'images' => [
                    'https://images.unsplash.com/photo-1556228578-0d85b1a4d571?w=800',
                    'https://images.unsplash.com/photo-1608248543803-ba4f8c70ae0b?w=800',
                ]
            ],
            [
                'category_id' => 5,
                'subcategory_id' => 31,
                'name' => 'Parfum Luxe Pour Homme 100ml',
                'description' => 'Eau de parfum masculine aux notes boisées et épicées. Fragrance longue durée dans un flacon élégant. Parfait pour toute occasion.',
                'price' => 95000,
                'stock' => 30,
                'weight_category' => 'X-small',
                'latitude' => 3.8445,
                'longitude' => 11.5043,
                'images' => [
                    'https://images.unsplash.com/photo-1541643600914-78b084683601?w=800',
                    'https://images.unsplash.com/photo-1592945403244-b3fbafd7f539?w=800',
                ]
            ],

            // Food & Beverages
            [
                'category_id' => 8,
                'subcategory_id' => 53,
                'name' => 'Café Premium Arabica 1kg',
                'description' => 'Grains de café 100% Arabica torréfiés artisanalement. Origine Éthiopie. Notes fruitées et chocolatées. Conditionné sous vide pour fraîcheur.',
                'price' => 25000,
                'stock' => 60,
                'weight_category' => '30 Deep',
                'latitude' => 3.8534,
                'longitude' => 11.5089,
                'images' => [
                    'https://images.unsplash.com/photo-1559056199-641a0ac8b55e?w=800',
                    'https://images.unsplash.com/photo-1587734195503-904fca47e0e9?w=800',
                ]
            ],
            [
                'category_id' => 8,
                'subcategory_id' => 50,
                'name' => 'Assortiment Chocolats Fins 500g',
                'description' => 'Boîte de chocolats belges assortis: noir, lait, blanc avec diverses garnitures. Parfait pour offrir ou se faire plaisir.',
                'price' => 18000,
                'stock' => 55,
                'weight_category' => 'X-small',
                'latitude' => 3.8412,
                'longitude' => 11.4978,
                'images' => [
                    'https://images.unsplash.com/photo-1511381939415-e44015466834?w=800',
                    'https://images.unsplash.com/photo-1481391032119-d89fee407e44?w=800',
                ]
            ],

            // Automotive
            [
                'category_id' => 9,
                'subcategory_id' => 56,
                'name' => 'Dashcam Full HD 1080p',
                'description' => 'Caméra embarquée avec vision nocturne, GPS intégré, détection de mouvement et enregistrement en boucle. Écran LCD 3 pouces.',
                'price' => 45000,
                'stock' => 35,
                'weight_category' => 'X-small',
                'latitude' => 3.8567,
                'longitude' => 11.5056,
                'images' => [
                    'https://images.unsplash.com/photo-1612538498456-e861df91d4d0?w=800',
                    'https://images.unsplash.com/photo-1583952734926-1f8a947f8e46?w=800',
                ]
            ],

            // Toys
            [
                'category_id' => 7,
                'subcategory_id' => 43,
                'name' => 'Lego City Set Construction',
                'description' => 'Set de construction LEGO avec 450 pièces, comprenant véhicules, figurines et bâtiments. Développe créativité et motricité. Âge 4-7 ans.',
                'price' => 38000,
                'stock' => 40,
                'weight_category' => '30 Deep',
                'latitude' => 3.8493,
                'longitude' => 11.5123,
                'images' => [
                    'https://images.unsplash.com/photo-1587654780291-39c9404d746b?w=800',
                    'https://images.unsplash.com/photo-1596461404969-9ae70f2830c1?w=800',
                ]
            ],

            // Home Appliances
            [
                'category_id' => 14,
                'subcategory_id' => 86,
                'name' => 'Mixeur Blender Professionnel',
                'description' => 'Blender haute performance 1200W avec bol en verre 2L, 5 vitesses et fonction pulse. Lames en acier inoxydable. Parfait pour smoothies.',
                'price' => 42000,
                'stock' => 28,
                'weight_category' => '50 Deep',
                'latitude' => 3.8521,
                'longitude' => 11.4989,
                'images' => [
                    'https://images.unsplash.com/photo-1585515320310-259814833e62?w=800',
                    'https://images.unsplash.com/photo-1570222094114-d054a817e56b?w=800',
                ]
            ],

            // Office
            [
                'category_id' => 11,
                'subcategory_id' => 67,
                'name' => 'Agenda Cuir 2026 Premium',
                'description' => 'Agenda journalier en cuir véritable avec pages datées, marque-pages et poche intérieure. Format A5. Élégant et pratique pour professionnels.',
                'price' => 22000,
                'stock' => 45,
                'weight_category' => 'X-small',
                'latitude' => 3.8456,
                'longitude' => 11.5134,
                'images' => [
                    'https://images.unsplash.com/photo-1531346878377-a5be20888e57?w=800',
                    'https://images.unsplash.com/photo-1554224311-beee4ece0933?w=800',
                ]
            ],

            // Pet Supplies
            [
                'category_id' => 10,
                'subcategory_id' => 61,
                'name' => 'Panier Chien Confort XXL',
                'description' => 'Lit orthopédique pour chien avec mousse à mémoire de forme, housse amovible et lavable. Taille XXL pour grands chiens. Antidérapant.',
                'price' => 48000,
                'stock' => 22,
                'weight_category' => '60 Deep',
                'latitude' => 3.8579,
                'longitude' => 11.5102,
                'images' => [
                    'https://images.unsplash.com/photo-1611003228941-98852ba62227?w=800',
                    'https://images.unsplash.com/photo-1623387641168-d9803ddd3f35?w=800',
                ]
            ],
        ];

        foreach ($products as $productData) {
            $images = $productData['images'];
            unset($productData['images']);

            // Create product
            $product = Product::create([
                'user_id' => $userId,
                'shop_id' => $shopId,
                'category_id' => $productData['category_id'],
                'subcategory_id' => $productData['subcategory_id'],
                'name' => $productData['name'],
                'description' => $productData['description'],
                'price' => $productData['price'],
                'stock' => $productData['stock'],
                'weight_category' => $productData['weight_category'],
                'latitude' => $productData['latitude'],
                'longitude' => $productData['longitude'],
                'price_type' => 'fixed',
                'type' => 'article',
                'status' => 'active',
            ]);

            // Download and attach images
            foreach ($images as $index => $imageUrl) {
                try {
                    // Download image from URL
                    $response = Http::timeout(30)->get($imageUrl);

                    if ($response->successful()) {
                        $imageContent = $response->body();
                        $extension = 'jpg';
                        $filename = 'products/' . uniqid() . '.' . $extension;

                        // Save to storage
                        Storage::disk('public')->put($filename, $imageContent);

                        // Create ProductImage record
                        ProductImage::create([
                            'product_id' => $product->id,
                            'image_path' => $filename,
                            'is_primary' => $index === 0,
                            'order' => $index,
                        ]);

                        $this->command->info("Image downloaded for product: {$product->name}");
                    }
                } catch (\Exception $e) {
                    $this->command->error("Failed to download image for {$product->name}: " . $e->getMessage());
                }
            }
        }

        $this->command->info('Products seeded successfully!');
    }
}
