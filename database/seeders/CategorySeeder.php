<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Category;
use App\Models\Subcategory;
use Illuminate\Support\Str;

class CategorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $categories = [
            [
                'name' => 'Électronique',
                'name_en' => 'Electronics',
                'description' => 'Appareils électroniques et accessoires',
                'svg_icon' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>',
                'subcategories' => [
                    ['name' => 'Ordinateurs & Laptops', 'name_en' => 'Computers & Laptops'],
                    ['name' => 'Smartphones & Tablettes', 'name_en' => 'Smartphones & Tablets'],
                    ['name' => 'Télévisions & Audio', 'name_en' => 'TVs & Audio'],
                    ['name' => 'Appareils photo & Caméras', 'name_en' => 'Cameras & Photography'],
                    ['name' => 'Accessoires électroniques', 'name_en' => 'Electronic Accessories'],
                    ['name' => 'Montres connectées', 'name_en' => 'Smart Watches'],
                    ['name' => 'Écouteurs & Casques', 'name_en' => 'Headphones & Earbuds'],
                ]
            ],
            [
                'name' => 'Mode & Vêtements',
                'name_en' => 'Fashion & Clothing',
                'description' => 'Vêtements et accessoires de mode',
                'svg_icon' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"/></svg>',
                'subcategories' => [
                    ['name' => 'Vêtements Hommes', 'name_en' => 'Men\'s Clothing'],
                    ['name' => 'Vêtements Femmes', 'name_en' => 'Women\'s Clothing'],
                    ['name' => 'Vêtements Enfants', 'name_en' => 'Kids\' Clothing'],
                    ['name' => 'Chaussures', 'name_en' => 'Shoes'],
                    ['name' => 'Sacs & Bagages', 'name_en' => 'Bags & Luggage'],
                    ['name' => 'Bijoux & Montres', 'name_en' => 'Jewelry & Watches'],
                    ['name' => 'Accessoires', 'name_en' => 'Accessories'],
                ]
            ],
            [
                'name' => 'Maison & Jardin',
                'name_en' => 'Home & Garden',
                'description' => 'Articles pour la maison et le jardin',
                'svg_icon' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>',
                'subcategories' => [
                    ['name' => 'Meubles', 'name_en' => 'Furniture'],
                    ['name' => 'Décoration intérieure', 'name_en' => 'Home Decor'],
                    ['name' => 'Cuisine & Salle à manger', 'name_en' => 'Kitchen & Dining'],
                    ['name' => 'Literie & Linge de maison', 'name_en' => 'Bedding & Linens'],
                    ['name' => 'Jardinage', 'name_en' => 'Gardening'],
                    ['name' => 'Outils & Bricolage', 'name_en' => 'Tools & Hardware'],
                    ['name' => 'Éclairage', 'name_en' => 'Lighting'],
                ]
            ],
            [
                'name' => 'Sports & Loisirs',
                'name_en' => 'Sports & Recreation',
                'description' => 'Équipements sportifs et loisirs',
                'svg_icon' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.828 14.828a4 4 0 01-5.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>',
                'subcategories' => [
                    ['name' => 'Fitness & Musculation', 'name_en' => 'Fitness & Gym'],
                    ['name' => 'Sports d\'équipe', 'name_en' => 'Team Sports'],
                    ['name' => 'Sports nautiques', 'name_en' => 'Water Sports'],
                    ['name' => 'Vélos & Cyclisme', 'name_en' => 'Bikes & Cycling'],
                    ['name' => 'Camping & Randonnée', 'name_en' => 'Camping & Hiking'],
                    ['name' => 'Sports de raquette', 'name_en' => 'Racket Sports'],
                    ['name' => 'Équipement sportif', 'name_en' => 'Sports Equipment'],
                ]
            ],
            [
                'name' => 'Beauté & Santé',
                'name_en' => 'Beauty & Health',
                'description' => 'Produits de beauté et santé',
                'svg_icon' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"/></svg>',
                'subcategories' => [
                    ['name' => 'Soins de la peau', 'name_en' => 'Skincare'],
                    ['name' => 'Maquillage', 'name_en' => 'Makeup'],
                    ['name' => 'Parfums', 'name_en' => 'Fragrances'],
                    ['name' => 'Soins capillaires', 'name_en' => 'Hair Care'],
                    ['name' => 'Vitamines & Suppléments', 'name_en' => 'Vitamins & Supplements'],
                    ['name' => 'Hygiène personnelle', 'name_en' => 'Personal Care'],
                    ['name' => 'Équipement médical', 'name_en' => 'Medical Equipment'],
                ]
            ],
            [
                'name' => 'Livres & Médias',
                'name_en' => 'Books & Media',
                'description' => 'Livres, films, musique et médias',
                'svg_icon' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/></svg>',
                'subcategories' => [
                    ['name' => 'Livres', 'name_en' => 'Books'],
                    ['name' => 'E-books & Kindle', 'name_en' => 'E-books & Kindle'],
                    ['name' => 'Films & Séries', 'name_en' => 'Movies & TV'],
                    ['name' => 'Musique & Vinyles', 'name_en' => 'Music & Vinyl'],
                    ['name' => 'Jeux vidéo', 'name_en' => 'Video Games'],
                    ['name' => 'Magazines & Journaux', 'name_en' => 'Magazines & Newspapers'],
                ]
            ],
            [
                'name' => 'Jouets & Enfants',
                'name_en' => 'Toys & Kids',
                'description' => 'Jouets et articles pour enfants',
                'svg_icon' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.828 14.828a4 4 0 01-5.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>',
                'subcategories' => [
                    ['name' => 'Jouets 0-3 ans', 'name_en' => 'Toys 0-3 years'],
                    ['name' => 'Jouets 4-7 ans', 'name_en' => 'Toys 4-7 years'],
                    ['name' => 'Jouets 8+ ans', 'name_en' => 'Toys 8+ years'],
                    ['name' => 'Jeux de société', 'name_en' => 'Board Games'],
                    ['name' => 'Puzzles', 'name_en' => 'Puzzles'],
                    ['name' => 'Poupées & Figurines', 'name_en' => 'Dolls & Action Figures'],
                    ['name' => 'Jouets éducatifs', 'name_en' => 'Educational Toys'],
                ]
            ],
            [
                'name' => 'Alimentation & Boissons',
                'name_en' => 'Food & Beverages',
                'description' => 'Produits alimentaires et boissons',
                'svg_icon' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"/></svg>',
                'subcategories' => [
                    ['name' => 'Épicerie', 'name_en' => 'Grocery'],
                    ['name' => 'Snacks & Confiseries', 'name_en' => 'Snacks & Candy'],
                    ['name' => 'Boissons', 'name_en' => 'Beverages'],
                    ['name' => 'Produits biologiques', 'name_en' => 'Organic Products'],
                    ['name' => 'Café & Thé', 'name_en' => 'Coffee & Tea'],
                    ['name' => 'Produits surgelés', 'name_en' => 'Frozen Foods'],
                ]
            ],
            [
                'name' => 'Automobile & Moto',
                'name_en' => 'Automotive & Motorcycle',
                'description' => 'Pièces et accessoires auto/moto',
                'svg_icon' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7v8a2 2 0 002 2h6M8 7V5a2 2 0 012-2h4.586a1 1 0 01.707.293l4.414 4.414a1 1 0 01.293.707V15a2 2 0 01-2 2h-2M8 7H6a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2v-2"/></svg>',
                'subcategories' => [
                    ['name' => 'Pièces détachées auto', 'name_en' => 'Car Parts'],
                    ['name' => 'Accessoires auto', 'name_en' => 'Car Accessories'],
                    ['name' => 'Motos & Scooters', 'name_en' => 'Motorcycles & Scooters'],
                    ['name' => 'Pneus & Jantes', 'name_en' => 'Tires & Wheels'],
                    ['name' => 'Entretien & Réparation', 'name_en' => 'Maintenance & Repair'],
                    ['name' => 'Électronique automobile', 'name_en' => 'Car Electronics'],
                ]
            ],
            [
                'name' => 'Animalerie',
                'name_en' => 'Pet Supplies',
                'description' => 'Produits pour animaux de compagnie',
                'svg_icon' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"/></svg>',
                'subcategories' => [
                    ['name' => 'Chiens', 'name_en' => 'Dogs'],
                    ['name' => 'Chats', 'name_en' => 'Cats'],
                    ['name' => 'Oiseaux', 'name_en' => 'Birds'],
                    ['name' => 'Poissons & Aquariums', 'name_en' => 'Fish & Aquariums'],
                    ['name' => 'Petits animaux', 'name_en' => 'Small Pets'],
                    ['name' => 'Nourriture animaux', 'name_en' => 'Pet Food'],
                ]
            ],
            [
                'name' => 'Bureautique & Fournitures',
                'name_en' => 'Office & Stationery',
                'description' => 'Fournitures de bureau et papeterie',
                'svg_icon' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>',
                'subcategories' => [
                    ['name' => 'Papeterie', 'name_en' => 'Stationery'],
                    ['name' => 'Fournitures scolaires', 'name_en' => 'School Supplies'],
                    ['name' => 'Matériel de bureau', 'name_en' => 'Office Equipment'],
                    ['name' => 'Imprimantes & Scanners', 'name_en' => 'Printers & Scanners'],
                    ['name' => 'Organisateurs', 'name_en' => 'Organizers'],
                    ['name' => 'Accessoires bureau', 'name_en' => 'Office Accessories'],
                ]
            ],
            [
                'name' => 'Bébé & Puériculture',
                'name_en' => 'Baby & Childcare',
                'description' => 'Produits pour bébés et puériculture',
                'svg_icon' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg>',
                'subcategories' => [
                    ['name' => 'Vêtements bébé', 'name_en' => 'Baby Clothing'],
                    ['name' => 'Alimentation bébé', 'name_en' => 'Baby Feeding'],
                    ['name' => 'Poussettes & Sièges auto', 'name_en' => 'Strollers & Car Seats'],
                    ['name' => 'Couches & Hygiène', 'name_en' => 'Diapers & Hygiene'],
                    ['name' => 'Chambre bébé', 'name_en' => 'Baby Room'],
                    ['name' => 'Jouets d\'éveil', 'name_en' => 'Baby Toys'],
                ]
            ],
            [
                'name' => 'Art & Loisirs créatifs',
                'name_en' => 'Arts & Crafts',
                'description' => 'Matériel artistique et loisirs créatifs',
                'svg_icon' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21a4 4 0 01-4-4V5a2 2 0 012-2h4a2 2 0 012 2v12a4 4 0 01-4 4zm0 0h12a2 2 0 002-2v-4a2 2 0 00-2-2h-2.343M11 7.343l1.657-1.657a2 2 0 012.828 0l2.829 2.829a2 2 0 010 2.828l-8.486 8.485M7 17h.01"/></svg>',
                'subcategories' => [
                    ['name' => 'Peinture & Dessin', 'name_en' => 'Painting & Drawing'],
                    ['name' => 'Loisirs créatifs', 'name_en' => 'Crafts'],
                    ['name' => 'Couture & Tricot', 'name_en' => 'Sewing & Knitting'],
                    ['name' => 'Scrapbooking', 'name_en' => 'Scrapbooking'],
                    ['name' => 'Instruments de musique', 'name_en' => 'Musical Instruments'],
                    ['name' => 'Accessoires artistiques', 'name_en' => 'Art Supplies'],
                ]
            ],
            [
                'name' => 'Électroménager',
                'name_en' => 'Home Appliances',
                'description' => 'Appareils électroménagers',
                'svg_icon' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 3v2m6-2v2M9 19v2m6-2v2M5 9H3m2 6H3m18-6h-2m2 6h-2M7 19h10a2 2 0 002-2V7a2 2 0 00-2-2H7a2 2 0 00-2 2v10a2 2 0 002 2zM9 9h6v6H9V9z"/></svg>',
                'subcategories' => [
                    ['name' => 'Gros électroménager', 'name_en' => 'Major Appliances'],
                    ['name' => 'Petit électroménager', 'name_en' => 'Small Appliances'],
                    ['name' => 'Aspirateurs & Nettoyage', 'name_en' => 'Vacuum Cleaners'],
                    ['name' => 'Climatisation & Chauffage', 'name_en' => 'Heating & Cooling'],
                    ['name' => 'Cafetières & Machines', 'name_en' => 'Coffee Makers'],
                    ['name' => 'Pièces détachées', 'name_en' => 'Appliance Parts'],
                ]
            ],
            [
                'name' => 'Bagages & Voyage',
                'name_en' => 'Luggage & Travel',
                'description' => 'Bagages et accessoires de voyage',
                'svg_icon' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>',
                'subcategories' => [
                    ['name' => 'Valises', 'name_en' => 'Suitcases'],
                    ['name' => 'Sacs à dos', 'name_en' => 'Backpacks'],
                    ['name' => 'Sacs de voyage', 'name_en' => 'Travel Bags'],
                    ['name' => 'Accessoires de voyage', 'name_en' => 'Travel Accessories'],
                    ['name' => 'Organisateurs de voyage', 'name_en' => 'Travel Organizers'],
                ]
            ],
        ];

        foreach ($categories as $categoryData) {
            $subcategories = $categoryData['subcategories'];
            unset($categoryData['subcategories']);

            // Generate slug
            $categoryData['slug'] = Str::slug($categoryData['name']);

            // Create category (idempotent : re-seed sans doublon)
            $category = Category::updateOrCreate(['slug' => $categoryData['slug']], $categoryData);

            // Create subcategories
            foreach ($subcategories as $subcategoryData) {
                $subcategoryData['category_id'] = $category->id;
                $subcategoryData['slug'] = Str::slug($subcategoryData['name']);
                Subcategory::updateOrCreate(
                    ['category_id' => $category->id, 'slug' => $subcategoryData['slug']],
                    $subcategoryData
                );
            }
        }

        $this->command->info('✅ Categories and subcategories seeded successfully!');
        $this->command->info('📦 Total: ' . count($categories) . ' categories created');
    }
}
