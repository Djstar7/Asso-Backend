<?php

namespace Database\Seeders;

use App\Imports\BooksImport;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Bibliothèque de démonstration : rayons et genres, une librairie vérifiée
 * et 20 livres fictifs importés par le vrai import (BooksImport), dont 3
 * avec leur couverture.
 *
 * Réexécutable : les rayons, la boutique et les livres (par ISBN) sont
 * retrouvés au lieu d'être recréés.
 *
 *   php artisan db:seed --class=LibraryDemoSeeder
 */
class LibraryDemoSeeder extends Seeder
{
    private const DATA = 'seeders/data/bibliotheque';

    private const SHELVES = [
        'Romans' => ['en' => 'Novels', 'genres' => ['Littérature africaine' => 'African literature', 'Policier' => 'Crime', 'Poésie' => 'Poetry']],
        'Jeunesse' => ['en' => 'Children', 'genres' => ['Contes' => 'Tales', 'Albums' => 'Picture books']],
        'Scolaire' => ['en' => 'School', 'genres' => ['Collège' => 'Middle school', 'Lycée' => 'High school']],
        'Pratique' => ['en' => 'Practical', 'genres' => ['Cuisine' => 'Cooking', 'Entrepreneuriat' => 'Entrepreneurship']],
    ];

    private const COVERS = [
        'Le Fleuve des promesses' => 'livre-le-fleuve-des-promesses-couverture.jpg',
        'Contes du soir au village' => 'livre-contes-du-soir-au-village-couverture.jpg',
        'Créer son entreprise à Douala' => 'livre-creer-son-entreprise-a-douala-couverture.jpg',
    ];

    public function run(): void
    {
        $this->seedShelves();
        $shop = $this->seedShop();

        $import = new BooksImport($shop);
        Excel::import($import, database_path(self::DATA . '/livres.xlsx'));

        foreach ($import->errors as $error) {
            $this->command?->warn("Ligne {$error['line']} : {$error['message']}");
        }

        $covers = $this->attachCovers($shop);

        $this->command?->info("Bibliothèque : {$import->created} livres créés, {$import->updated} mis à jour, {$covers} couvertures, boutique « {$shop->name} ».");
    }

    private function seedShelves(): void
    {
        foreach (self::SHELVES as $name => $shelf) {
            $category = Category::firstOrCreate(
                ['slug' => Str::slug($name)],
                ['name' => $name, 'name_en' => $shelf['en'], 'is_library' => true],
            );
            if (! $category->is_library) {
                // Une catégorie marchande porte déjà ce nom : on ne la transforme pas en rayon.
                $this->command?->warn("Catégorie « {$name} » existante hors Bibliothèque, rayon ignoré.");

                continue;
            }

            foreach ($shelf['genres'] as $genre => $english) {
                $category->subcategories()->firstOrCreate(
                    ['slug' => Str::slug($genre)],
                    ['name' => $genre, 'name_en' => $english],
                );
            }
        }
    }

    private function seedShop(): Shop
    {
        $owner = User::updateOrCreate(
            ['email' => 'librairie.demo@asso.test'],
            [
                'first_name' => 'Librairie',
                'last_name' => 'Démo',
                'password' => 'Password123!',
                'role' => 'vendeur',
                'roles' => ['vendeur'],
                'phone' => '+237690000778',
                'country' => 'Cameroun',
                'address' => 'Akwa, Douala, Littoral, Cameroun',
                'latitude' => 4.0511,
                'longitude' => 9.7679,
                'is_profile_complete' => true,
            ],
        );

        return Shop::withoutGlobalScopes()->updateOrCreate(
            ['user_id' => $owner->id, 'name' => 'Librairie du Wouri (démo)'],
            [
                'description' => 'Librairie de démonstration de la rubrique Bibliothèque.',
                'address' => 'Akwa, Douala, Littoral, Cameroun',
                'phone' => '+237690000778',
                'email' => 'librairie.demo@asso.test',
                'latitude' => 4.0511,
                'longitude' => 9.7679,
                'status' => 'active',
                'verified_at' => now(),
            ],
        );
    }

    private function attachCovers(Shop $shop): int
    {
        $attached = 0;
        File::ensureDirectoryExists(public_path('storage/products'));

        foreach (self::COVERS as $title => $file) {
            $product = Product::withoutGlobalScopes()
                ->where('shop_id', $shop->id)
                ->where('name', $title)
                ->first();
            if (! $product || $product->images()->exists()) {
                continue;
            }

            $name = Str::uuid() . '.jpg';
            File::copy(database_path(self::DATA . '/' . $file), public_path('storage/products/' . $name));
            ProductImage::create([
                'product_id' => $product->id,
                'image_path' => 'storage/products/' . $name,
                'is_primary' => true,
                'order' => 1,
            ]);
            $attached++;
        }

        return $attached;
    }
}
