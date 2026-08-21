<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\DelivererCodeSync;
use App\Models\DelivererCompany;
use App\Models\DelivererSyncCode;
use App\Models\DeliveryPricelist;
use App\Models\DeliveryZone;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Jeu de données de démonstration pour le flux "achat + livraison de proximité"
 * autour de Baleng / Bafoussam II (Ouest, Cameroun) ~ (5.47 N, 10.42 E).
 *
 * Crée :
 *  - 1 vendeur CERTIFIÉ + sa boutique certifiée
 *  - 5 produits (prix 100–300 XAF)
 *  - 1 compagnie de livraison + zone centrée sur Baleng + grille tarifaire
 *  - 1 livreur SYNCHRONISÉ à la compagnie (code + pivot code_sync actif)
 *
 * Idempotent : réexécutable sans créer de doublons (clés = email / nom).
 *
 *   php artisan db:seed --class=DemoBalengLivraisonSeeder
 */
class DemoBalengLivraisonSeeder extends Seeder
{
    // Centre géographique de la zone = position acheteur (Bafoussam / Baleng, Ouest)
    private const BALENG_LAT = 5.48210000;
    private const BALENG_LNG = 10.41690000;

    public function run(): void
    {
        DB::transaction(function () {
            // ------------------------------------------------------------------
            // 1) VENDEUR CERTIFIÉ
            // ------------------------------------------------------------------
            $vendeur = User::updateOrCreate(
                ['email' => 'vendeur.baleng@asso.test'],
                [
                    'first_name'          => 'Boutique',
                    'last_name'           => 'Baleng',
                    'password'            => 'Password123!', // hashé automatiquement (cast hashed)
                    'role'                => 'vendeur',
                    'roles'               => ['vendeur'],
                    'phone'               => '+237690000101',
                    'country'             => 'Cameroun',
                    'address'             => 'Cité des Palmiers, Douala V, Littoral, Cameroun',
                    'latitude'            => self::BALENG_LAT,
                    'longitude'           => self::BALENG_LNG,
                    'is_profile_complete' => true,
                ]
            );

            // ------------------------------------------------------------------
            // 2) BOUTIQUE certifiée + vérifiée
            // ------------------------------------------------------------------
            $shop = Shop::updateOrCreate(
                ['user_id' => $vendeur->id, 'name' => 'Boutique Baleng Market'],
                [
                    'description'              => 'Boutique de démonstration à la Cité des Palmiers, Douala V.',
                    'address'                  => 'Cité des Palmiers, Douala V, Littoral, Cameroun',
                    'phone'                    => '+237690000101',
                    'email'                    => 'vendeur.baleng@asso.test',
                    'latitude'                 => self::BALENG_LAT,
                    'longitude'                => self::BALENG_LNG,
                    'status'                   => 'active',
                    'verified_at'              => now(),
                    'is_certified'             => true,
                    'certified_at'             => now(),
                    'certification_expires_at' => null, // n'expire jamais
                ]
            );

            // ------------------------------------------------------------------
            // 3) 5 PRODUITS (prix 100–300 XAF)
            // ------------------------------------------------------------------
            $categoryId = Category::query()->value('id') ?? Category::create(['name' => 'Général'])->id;

            $produits = [
                ['name' => 'Sac de riz parfumé 1kg',      'price' => 150, 'description' => 'Riz parfumé de qualité, sachet 1 kg.'],
                ['name' => 'Bidon d’huile végétale 1L',   'price' => 300, 'description' => 'Huile végétale raffinée, bouteille 1 L.'],
                ['name' => 'Paquet de spaghetti',         'price' => 200, 'description' => 'Pâtes alimentaires spaghetti 500 g.'],
                ['name' => 'Boîte de sardines',           'price' => 250, 'description' => 'Sardines à l’huile, boîte 125 g.'],
                ['name' => 'Savon de ménage',             'price' => 100, 'description' => 'Savon de ménage multi-usage.'],
            ];

            foreach ($produits as $p) {
                $product = Product::updateOrCreate(
                    ['user_id' => $vendeur->id, 'name' => $p['name']],
                    [
                        'shop_id'         => $shop->id,
                        'category_id'     => $categoryId,
                        'description'     => $p['description'],
                        'price'           => $p['price'],
                        'currency'        => 'XAF', // price_xaf recalculé automatiquement
                        'price_type'      => 'fixed',
                        'type'            => 'article',
                        'stock'           => 50,
                        'weight_category' => 'X-small',
                        'latitude'        => self::BALENG_LAT,
                        'longitude'       => self::BALENG_LNG,
                        'status'          => 'active',
                    ]
                );

                ProductImage::updateOrCreate(
                    ['product_id' => $product->id, 'is_primary' => true],
                    ['image_path' => 'products/demo/placeholder.jpg', 'order' => 0]
                );
            }

            // ------------------------------------------------------------------
            // 4) LIVREUR (compte User rôle livreur)
            // ------------------------------------------------------------------
            $livreur = User::updateOrCreate(
                ['email' => 'livreur.baleng@asso.test'],
                [
                    'first_name'          => 'Jean',
                    'last_name'           => 'Livreur',
                    'password'            => 'Password123!',
                    'role'                => 'livreur',
                    'roles'               => ['livreur'],
                    'phone'               => '+237690000202',
                    'country'             => 'Cameroun',
                    'address'             => 'Cité des Palmiers, Douala V, Littoral, Cameroun',
                    'latitude'            => self::BALENG_LAT,
                    'longitude'           => self::BALENG_LNG,
                    'is_profile_complete' => true,
                ]
            );

            // ------------------------------------------------------------------
            // 5) COMPAGNIE DE LIVRAISON (rattachée au livreur synchronisé)
            // ------------------------------------------------------------------
            $company = DelivererCompany::updateOrCreate(
                ['name' => 'Express Baleng Livraison'],
                [
                    'user_id'     => $livreur->id,
                    'phone'       => '+237690000202',
                    'email'       => 'contact.express.baleng@asso.test',
                    'description' => 'Compagnie de livraison de démonstration couvrant la Cité des Palmiers, Douala V.',
                    'is_active'   => true,
                ]
            );

            // ------------------------------------------------------------------
            // 6) ZONE DE LIVRAISON centrée sur Baleng (rayon utile ≤ 10 km)
            // ------------------------------------------------------------------
            $zone = DeliveryZone::updateOrCreate(
                ['deliverer_company_id' => $company->id, 'name' => 'Zone Bafoussam - Baleng'],
                [
                    'city'             => 'Bafoussam',
                    'zone_data'        => null,
                    'center_latitude'  => self::BALENG_LAT,
                    'center_longitude' => self::BALENG_LNG,
                    'is_active'        => true,
                ]
            );

            // ------------------------------------------------------------------
            // 7) GRILLE TARIFAIRE (prix fixe + commission ASSO)
            // ------------------------------------------------------------------
            DeliveryPricelist::updateOrCreate(
                ['delivery_zone_id' => $zone->id],
                [
                    'pricing_type'    => DeliveryPricelist::PRICING_TYPE_FIXED,
                    'pricing_data'    => ['price' => 500], // 500 XAF de base
                    'asso_commission' => 100,              // + 100 XAF commission
                    'is_active'       => true,
                ]
            );

            // ------------------------------------------------------------------
            // 8) SYNCHRONISATION DU LIVREUR (code + pivot actif)
            // ------------------------------------------------------------------
            $syncCode = DelivererSyncCode::firstOrCreate(
                ['company_id' => $company->id, 'user_id' => $livreur->id],
                [
                    'sync_code'  => DelivererSyncCode::generateSyncCode(),
                    'is_used'    => true,
                    'sent_via'   => 'all',
                    'sent_at'    => now(),
                    'used_at'    => now(),
                    'expires_at' => now()->addDays(30),
                ]
            );

            // Pivot livreur <-> compagnie : INDISPENSABLE pour que le livreur
            // reçoive les demandes de livraison (filtre DelivererCodeSync::active()).
            DelivererCodeSync::updateOrCreate(
                ['user_id' => $livreur->id, 'company_id' => $company->id],
                [
                    'sync_code_id' => $syncCode->id,
                    'is_active'    => true,
                    'is_banned'    => false,
                    'synced_at'    => now(),
                ]
            );

            $this->command?->info('--- Démo Baleng créée ---');
            $this->command?->info("Vendeur certifié : {$vendeur->email} (id {$vendeur->id}) / mot de passe: Password123!");
            $this->command?->info("Boutique        : {$shop->name} (certifiée)");
            $this->command?->info('Produits        : 5 produits 100–300 XAF');
            $this->command?->info("Compagnie       : {$company->name} (id {$company->id})");
            $this->command?->info("Livreur         : {$livreur->email} (id {$livreur->id}) synchronisé");
            $this->command?->info("Zone            : centre ({$zone->center_latitude}, {$zone->center_longitude})");
        });
    }
}
