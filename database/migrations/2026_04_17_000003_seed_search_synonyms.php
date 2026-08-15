<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Seed initial synonyms for intelligent search
     */
    public function up(): void
    {
        $synonyms = [
            // Informatique & Électronique
            ['term' => 'pc', 'synonym' => 'ordinateur', 'weight' => 10],
            ['term' => 'pc', 'synonym' => 'computer', 'weight' => 8],
            ['term' => 'ordinateur', 'synonym' => 'pc', 'weight' => 10],
            ['term' => 'ordinateur', 'synonym' => 'computer', 'weight' => 8],
            ['term' => 'computer', 'synonym' => 'ordinateur', 'weight' => 10],
            ['term' => 'computer', 'synonym' => 'pc', 'weight' => 8],
            ['term' => 'portable', 'synonym' => 'laptop', 'weight' => 10],
            ['term' => 'laptop', 'synonym' => 'portable', 'weight' => 10],
            ['term' => 'smartphone', 'synonym' => 'téléphone', 'weight' => 9],
            ['term' => 'smartphone', 'synonym' => 'mobile', 'weight' => 9],
            ['term' => 'téléphone', 'synonym' => 'smartphone', 'weight' => 9],
            ['term' => 'téléphone', 'synonym' => 'mobile', 'weight' => 9],
            ['term' => 'tablette', 'synonym' => 'tablet', 'weight' => 10],
            ['term' => 'tablet', 'synonym' => 'tablette', 'weight' => 10],
            ['term' => 'écran', 'synonym' => 'moniteur', 'weight' => 8],
            ['term' => 'écran', 'synonym' => 'display', 'weight' => 7],
            ['term' => 'moniteur', 'synonym' => 'écran', 'weight' => 8],
            ['term' => 'clavier', 'synonym' => 'keyboard', 'weight' => 8],
            ['term' => 'souris', 'synonym' => 'mouse', 'weight' => 8],
            ['term' => 'casque', 'synonym' => 'écouteurs', 'weight' => 7],
            ['term' => 'casque', 'synonym' => 'headphone', 'weight' => 7],
            ['term' => 'enceinte', 'synonym' => 'speaker', 'weight' => 7],
            ['term' => 'enceinte', 'synonym' => 'haut-parleur', 'weight' => 7],

            // Vêtements & Mode
            ['term' => 'vêtement', 'synonym' => 'habit', 'weight' => 8],
            ['term' => 'vêtement', 'synonym' => 'fringue', 'weight' => 6],
            ['term' => 'pantalon', 'synonym' => 'jean', 'weight' => 7],
            ['term' => 'pantalon', 'synonym' => 'pants', 'weight' => 7],
            ['term' => 'chemise', 'synonym' => 'shirt', 'weight' => 8],
            ['term' => 'chaussure', 'synonym' => 'shoe', 'weight' => 8],
            ['term' => 'chaussure', 'synonym' => 'soulier', 'weight' => 7],
            ['term' => 'basket', 'synonym' => 'sneaker', 'weight' => 9],
            ['term' => 'basket', 'synonym' => 'tennis', 'weight' => 8],
            ['term' => 'robe', 'synonym' => 'dress', 'weight' => 8],
            ['term' => 'sac', 'synonym' => 'bag', 'weight' => 7],
            ['term' => 'sac', 'synonym' => 'sacoche', 'weight' => 6],

            // Maison & Jardin
            ['term' => 'maison', 'synonym' => 'domicile', 'weight' => 6],
            ['term' => 'maison', 'synonym' => 'habitat', 'weight' => 5],
            ['term' => 'meuble', 'synonym' => 'furniture', 'weight' => 7],
            ['term' => 'canapé', 'synonym' => 'sofa', 'weight' => 9],
            ['term' => 'lit', 'synonym' => 'bed', 'weight' => 8],
            ['term' => 'table', 'synonym' => 'desk', 'weight' => 6],
            ['term' => 'chaise', 'synonym' => 'chair', 'weight' => 8],

            // Véhicules
            ['term' => 'voiture', 'synonym' => 'auto', 'weight' => 8],
            ['term' => 'voiture', 'synonym' => 'véhicule', 'weight' => 7],
            ['term' => 'voiture', 'synonym' => 'car', 'weight' => 7],
            ['term' => 'moto', 'synonym' => 'motocyclette', 'weight' => 7],
            ['term' => 'moto', 'synonym' => 'motorcycle', 'weight' => 7],
            ['term' => 'vélo', 'synonym' => 'bicyclette', 'weight' => 8],
            ['term' => 'vélo', 'synonym' => 'bike', 'weight' => 8],

            // Alimentation
            ['term' => 'nourriture', 'synonym' => 'aliment', 'weight' => 7],
            ['term' => 'nourriture', 'synonym' => 'food', 'weight' => 7],
            ['term' => 'boisson', 'synonym' => 'drink', 'weight' => 7],
            ['term' => 'eau', 'synonym' => 'water', 'weight' => 6],

            // Sports & Loisirs
            ['term' => 'sport', 'synonym' => 'fitness', 'weight' => 6],
            ['term' => 'ballon', 'synonym' => 'ball', 'weight' => 7],
            ['term' => 'jeu', 'synonym' => 'game', 'weight' => 7],
            ['term' => 'jouet', 'synonym' => 'toy', 'weight' => 8],

            // Livres & Culture
            ['term' => 'livre', 'synonym' => 'book', 'weight' => 8],
            ['term' => 'livre', 'synonym' => 'bouquin', 'weight' => 6],
            ['term' => 'magazine', 'synonym' => 'revue', 'weight' => 7],

            // Beauté & Santé
            ['term' => 'parfum', 'synonym' => 'fragrance', 'weight' => 7],
            ['term' => 'maquillage', 'synonym' => 'makeup', 'weight' => 8],
            ['term' => 'crème', 'synonym' => 'lotion', 'weight' => 6],

            // Termes généraux
            ['term' => 'neuf', 'synonym' => 'nouveau', 'weight' => 5],
            ['term' => 'neuf', 'synonym' => 'new', 'weight' => 5],
            ['term' => 'occasion', 'synonym' => 'used', 'weight' => 6],
            ['term' => 'occasion', 'synonym' => 'seconde main', 'weight' => 6],
            ['term' => 'pas cher', 'synonym' => 'économique', 'weight' => 5],
            ['term' => 'pas cher', 'synonym' => 'abordable', 'weight' => 5],
            ['term' => 'luxe', 'synonym' => 'premium', 'weight' => 6],
            ['term' => 'luxe', 'synonym' => 'haut de gamme', 'weight' => 6],
        ];

        $now = now();
        foreach ($synonyms as &$synonym) {
            $synonym['is_active'] = true;
            $synonym['created_at'] = $now;
            $synonym['updated_at'] = $now;
        }

        DB::table('search_synonyms')->insert($synonyms);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('search_synonyms')->truncate();
    }
};
