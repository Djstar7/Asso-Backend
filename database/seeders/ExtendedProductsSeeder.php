<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Inventory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class ExtendedProductsSeeder extends Seeder
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
            // More Electronics - Category 1
            [
                'category_id' => 1,
                'subcategory_id' => 2,
                'name' => 'Samsung Galaxy S24 Ultra',
                'description' => 'Smartphone premium avec écran AMOLED 6,8 pouces, processeur Snapdragon 8 Gen 3, 512Go stockage et S Pen intégré.',
                'price' => 780000,
                'stock' => 20,
                'weight_category' => 'X-small',
                'images' => [
                    'https://images.unsplash.com/photo-1610945415295-d9bbf067e59c?w=800',
                ]
            ],
            [
                'category_id' => 1,
                'subcategory_id' => 2,
                'name' => 'Google Pixel 8 Pro',
                'description' => 'Smartphone Google avec IA avancée, appareil photo exceptionnel, écran 120Hz et puce Tensor G3.',
                'price' => 650000,
                'stock' => 18,
                'weight_category' => 'X-small',
                'images' => [
                    'https://images.unsplash.com/photo-1598327105666-5b89351aff97?w=800',
                ]
            ],
            [
                'category_id' => 1,
                'subcategory_id' => 1,
                'name' => 'Dell XPS 15 Laptop',
                'description' => 'Laptop professionnel avec écran InfinityEdge 4K, Intel Core i7, 32Go RAM et carte graphique NVIDIA RTX.',
                'price' => 1450000,
                'stock' => 10,
                'weight_category' => '30 Deep',
                'images' => [
                    'https://images.unsplash.com/photo-1593642632823-8f785ba67e45?w=800',
                ]
            ],
            [
                'category_id' => 1,
                'subcategory_id' => 3,
                'name' => 'TV Samsung QLED 65 pouces',
                'description' => 'Téléviseur QLED 4K avec Quantum HDR, son Dolby Atmos et design ultra fin. Smart TV avec applications intégrées.',
                'price' => 950000,
                'stock' => 8,
                'weight_category' => 'Pallet',
                'images' => [
                    'https://images.unsplash.com/photo-1593359677879-a4bb92f829d1?w=800',
                ]
            ],
            [
                'category_id' => 1,
                'subcategory_id' => 3,
                'name' => 'Barre de son Sony HT-A7000',
                'description' => 'Barre de son premium avec Dolby Atmos, DTS:X, 11 haut-parleurs intégrés et subwoofer sans fil.',
                'price' => 485000,
                'stock' => 15,
                'weight_category' => '60 Deep',
                'images' => [
                    'https://images.unsplash.com/photo-1545454675-3531b543be5d?w=800',
                ]
            ],
            [
                'category_id' => 1,
                'subcategory_id' => 4,
                'name' => 'Canon EOS R6 Mark II',
                'description' => 'Appareil photo hybride plein format avec 24MP, AF intelligent et vidéo 4K 60fps. Parfait pour professionnels.',
                'price' => 1850000,
                'stock' => 5,
                'weight_category' => '50 Deep',
                'images' => [
                    'https://images.unsplash.com/photo-1606980704487-0072ec37082f?w=800',
                ]
            ],
            [
                'category_id' => 1,
                'subcategory_id' => 5,
                'name' => 'Chargeur Sans Fil Rapide 3-en-1',
                'description' => 'Station de charge pour iPhone, Apple Watch et AirPods. Charge rapide 15W avec design élégant.',
                'price' => 35000,
                'stock' => 60,
                'weight_category' => 'X-small',
                'images' => [
                    'https://images.unsplash.com/photo-1591290619762-c588f0e39109?w=800',
                ]
            ],
            [
                'category_id' => 1,
                'subcategory_id' => 6,
                'name' => 'Apple Watch Series 9',
                'description' => 'Montre connectée avec écran Always-On, suivi santé avancé, GPS et résistance à l\'eau. Cadran 45mm.',
                'price' => 320000,
                'stock' => 25,
                'weight_category' => 'X-small',
                'images' => [
                    'https://images.unsplash.com/photo-1434493789847-2f02dc6ca35d?w=800',
                ]
            ],
            [
                'category_id' => 1,
                'subcategory_id' => 7,
                'name' => 'Sony WH-1000XM5',
                'description' => 'Casque sans fil premium avec réduction de bruit de pointe, autonomie 30h et son Hi-Res Audio.',
                'price' => 275000,
                'stock' => 30,
                'weight_category' => 'X-small',
                'images' => [
                    'https://images.unsplash.com/photo-1546435770-a3e426bf472b?w=800',
                ]
            ],
            [
                'category_id' => 1,
                'subcategory_id' => 2,
                'name' => 'iPad Pro 12.9 pouces M2',
                'description' => 'Tablette professionnelle avec puce M2, écran Liquid Retina XDR, 256Go et support Apple Pencil.',
                'price' => 920000,
                'stock' => 12,
                'weight_category' => '30 Deep',
                'images' => [
                    'https://images.unsplash.com/photo-1544244015-0df4b3ffc6b0?w=800',
                ]
            ],

            // More Fashion - Category 2
            [
                'category_id' => 2,
                'subcategory_id' => 8,
                'name' => 'Veste Cuir Homme Premium',
                'description' => 'Veste en cuir véritable avec doublure satin, coupe moderne et finitions soignées. Style intemporel.',
                'price' => 185000,
                'stock' => 20,
                'weight_category' => '30 Deep',
                'images' => [
                    'https://images.unsplash.com/photo-1551028719-00167b16eac5?w=800',
                ]
            ],
            [
                'category_id' => 2,
                'subcategory_id' => 8,
                'name' => 'Chemise Lin Homme',
                'description' => 'Chemise 100% lin naturel, coupe décontractée, parfaite pour l\'été. Respirante et élégante.',
                'price' => 42000,
                'stock' => 45,
                'weight_category' => 'X-small',
                'images' => [
                    'https://images.unsplash.com/photo-1596755094514-f87e34085b2c?w=800',
                ]
            ],
            [
                'category_id' => 2,
                'subcategory_id' => 9,
                'name' => 'Ensemble Tailleur Femme',
                'description' => 'Tailleur-pantalon professionnel en polyester stretch, veste cintrée et pantalon droit. Plusieurs coloris.',
                'price' => 98000,
                'stock' => 28,
                'weight_category' => '30 Deep',
                'images' => [
                    'https://images.unsplash.com/photo-1573496359142-b8d87734a5a2?w=800',
                ]
            ],
            [
                'category_id' => 2,
                'subcategory_id' => 9,
                'name' => 'Jupe Midi Plissée',
                'description' => 'Jupe élégante mi-longue avec plissés délicats. Taille haute et tissu fluide. Parfaite pour toute occasion.',
                'price' => 38000,
                'stock' => 40,
                'weight_category' => 'X-small',
                'images' => [
                    'https://images.unsplash.com/photo-1583496661160-fb5886a0aaaa?w=800',
                ]
            ],
            [
                'category_id' => 2,
                'subcategory_id' => 10,
                'name' => 'Ensemble Bébé 6-12 mois',
                'description' => 'Set 5 pièces en coton bio pour bébé: bodies, pyjama et bonnet. Doux et confortable.',
                'price' => 32000,
                'stock' => 35,
                'weight_category' => 'X-small',
                'images' => [
                    'https://images.unsplash.com/photo-1519238263530-99bdd11df2ea?w=800',
                ]
            ],
            [
                'category_id' => 2,
                'subcategory_id' => 11,
                'name' => 'Adidas Ultraboost 23',
                'description' => 'Chaussures running avec technologie Boost, tige Primeknit et semelle Continental. Confort maximal.',
                'price' => 115000,
                'stock' => 42,
                'weight_category' => '30 Deep',
                'images' => [
                    'https://images.unsplash.com/photo-1608231387042-66d1773070a5?w=800',
                ]
            ],
            [
                'category_id' => 2,
                'subcategory_id' => 11,
                'name' => 'Sandales Femme Cuir',
                'description' => 'Sandales élégantes en cuir véritable avec bride réglable et semelle confort. Parfaites pour l\'été.',
                'price' => 48000,
                'stock' => 50,
                'weight_category' => 'X-small',
                'images' => [
                    'https://images.unsplash.com/photo-1603487742131-4160ec999306?w=800',
                ]
            ],
            [
                'category_id' => 2,
                'subcategory_id' => 12,
                'name' => 'Sac à Main Cuir Femme',
                'description' => 'Sac à main en cuir italien avec multiples compartiments, bandoulière ajustable et fermeture sécurisée.',
                'price' => 125000,
                'stock' => 22,
                'weight_category' => '30 Deep',
                'images' => [
                    'https://images.unsplash.com/photo-1584917865442-de89df76afd3?w=800',
                ]
            ],
            [
                'category_id' => 2,
                'subcategory_id' => 12,
                'name' => 'Valise Cabine Rigide',
                'description' => 'Valise cabine ABS avec 4 roues pivotantes, serrure TSA et compartiments organisés. Taille 55cm.',
                'price' => 78000,
                'stock' => 18,
                'weight_category' => '60 Deep',
                'images' => [
                    'https://images.unsplash.com/photo-1565026057447-bc90a3dceb87?w=800',
                ]
            ],
            [
                'category_id' => 2,
                'subcategory_id' => 13,
                'name' => 'Montre Homme Automatique',
                'description' => 'Montre mécanique avec mouvement automatique, bracelet acier inoxydable et boîtier saphir. Étanche 100m.',
                'price' => 285000,
                'stock' => 15,
                'weight_category' => 'X-small',
                'images' => [
                    'https://images.unsplash.com/photo-1523170335258-f5ed11844a49?w=800',
                ]
            ],

            // More Home & Garden - Category 3
            [
                'category_id' => 3,
                'subcategory_id' => 15,
                'name' => 'Table à Manger 6 Places',
                'description' => 'Table rectangulaire en bois massif avec pieds métalliques. Design industriel moderne. 180x90cm.',
                'price' => 285000,
                'stock' => 10,
                'weight_category' => 'Pallet',
                'images' => [
                    'https://images.unsplash.com/photo-1595428774223-ef52624120d2?w=800',
                ]
            ],
            [
                'category_id' => 3,
                'subcategory_id' => 15,
                'name' => 'Fauteuil Relax Ergonomique',
                'description' => 'Fauteuil confortable avec repose-pieds, revêtement tissu premium et structure robuste. Idéal pour salon.',
                'price' => 165000,
                'stock' => 14,
                'weight_category' => 'Rainbow XL',
                'images' => [
                    'https://images.unsplash.com/photo-1567538096630-e0c55bd6374c?w=800',
                ]
            ],
            [
                'category_id' => 3,
                'subcategory_id' => 16,
                'name' => 'Set Coussins Décoratifs 4pcs',
                'description' => 'Ensemble de coussins décoratifs avec housses amovibles, motifs modernes et garnissage moelleux.',
                'price' => 28000,
                'stock' => 55,
                'weight_category' => '30 Deep',
                'images' => [
                    'https://images.unsplash.com/photo-1584100936595-c0654b55a2e2?w=800',
                ]
            ],
            [
                'category_id' => 3,
                'subcategory_id' => 16,
                'name' => 'Miroir Mural Décoratif 80cm',
                'description' => 'Grand miroir rond avec cadre doré, parfait pour agrandir l\'espace. Fixation murale incluse.',
                'price' => 58000,
                'stock' => 25,
                'weight_category' => '60 Deep',
                'images' => [
                    'https://images.unsplash.com/photo-1618220179428-22790b461013?w=800',
                ]
            ],
            [
                'category_id' => 3,
                'subcategory_id' => 17,
                'name' => 'Batterie de Cuisine 12 Pièces',
                'description' => 'Set complet casseroles et poêles antiadhésives, compatible induction. Acier inoxydable haute qualité.',
                'price' => 95000,
                'stock' => 20,
                'weight_category' => '60 Deep',
                'images' => [
                    'https://images.unsplash.com/photo-1584990347449-39b4aa8ece3c?w=800',
                ]
            ],
            [
                'category_id' => 3,
                'subcategory_id' => 17,
                'name' => 'Set Couteaux Cuisine Professionnels',
                'description' => 'Ensemble 6 couteaux chef en acier inoxydable avec support bambou. Lames affûtées et ergonomiques.',
                'price' => 68000,
                'stock' => 30,
                'weight_category' => '30 Deep',
                'images' => [
                    'https://images.unsplash.com/photo-1593618998160-e34014e67546?w=800',
                ]
            ],
            [
                'category_id' => 3,
                'subcategory_id' => 18,
                'name' => 'Parure de Lit 200x200 Premium',
                'description' => 'Housse de couette et taies d\'oreiller en coton égyptien 400 fils. Douceur et qualité hôtel.',
                'price' => 75000,
                'stock' => 32,
                'weight_category' => '50 Deep',
                'images' => [
                    'https://images.unsplash.com/photo-1631049307264-da0ec9d70304?w=800',
                ]
            ],
            [
                'category_id' => 3,
                'subcategory_id' => 19,
                'name' => 'Set Jardinage Complet 10 Outils',
                'description' => 'Kit outils de jardinage avec sac de transport: pelle, râteau, sécateur, gants et plus. Acier traité.',
                'price' => 45000,
                'stock' => 28,
                'weight_category' => '50 Deep',
                'images' => [
                    'https://images.unsplash.com/photo-1416879595882-3373a0480b5b?w=800',
                ]
            ],
            [
                'category_id' => 3,
                'subcategory_id' => 20,
                'name' => 'Perceuse Visseuse Sans Fil 18V',
                'description' => 'Perceuse professionnelle avec 2 batteries lithium, chargeur rapide et mallette. 50Nm de couple.',
                'price' => 135000,
                'stock' => 18,
                'weight_category' => '60 Deep',
                'images' => [
                    'https://images.unsplash.com/photo-1504148455328-c376907d081c?w=800',
                ]
            ],
            [
                'category_id' => 3,
                'subcategory_id' => 21,
                'name' => 'Lampadaire LED Design Moderne',
                'description' => 'Lampadaire arc avec variateur, LED intégrée économique et pied en marbre. Hauteur ajustable.',
                'price' => 92000,
                'stock' => 22,
                'weight_category' => '60 Deep',
                'images' => [
                    'https://images.unsplash.com/photo-1507473885765-e6ed057f782c?w=800',
                ]
            ],

            // More Sports - Category 4
            [
                'category_id' => 4,
                'subcategory_id' => 22,
                'name' => 'Tapis de Course Électrique',
                'description' => 'Tapis de course pliable avec écran LCD, 12 programmes, vitesse jusqu\'à 12km/h et amortisseurs.',
                'price' => 485000,
                'stock' => 8,
                'weight_category' => 'Pallet',
                'images' => [
                    'https://images.unsplash.com/photo-1576678927484-cc907957088c?w=800',
                ]
            ],
            [
                'category_id' => 4,
                'subcategory_id' => 22,
                'name' => 'Banc de Musculation Multi-fonctions',
                'description' => 'Banc réglable avec support barre, pupitre biceps et butterfly. Charge max 200kg. Compact.',
                'price' => 195000,
                'stock' => 12,
                'weight_category' => 'Rainbow XL',
                'images' => [
                    'https://images.unsplash.com/photo-1534438327276-14e5300c3a48?w=800',
                ]
            ],
            [
                'category_id' => 4,
                'subcategory_id' => 23,
                'name' => 'Ballon Football Nike Premier League',
                'description' => 'Ballon officiel taille 5 avec technologie Aerowsculpt pour trajectoire précise. Coutures thermocollées.',
                'price' => 32000,
                'stock' => 65,
                'weight_category' => 'X-small',
                'images' => [
                    'https://images.unsplash.com/photo-1579952363873-27f3bade9f55?w=800',
                ]
            ],
            [
                'category_id' => 4,
                'subcategory_id' => 23,
                'name' => 'Panier Basketball Réglable',
                'description' => 'Panier sur pied avec hauteur ajustable 2,3-3m, panneau résistant et base remplissable. Pour extérieur.',
                'price' => 145000,
                'stock' => 10,
                'weight_category' => 'Pallet',
                'images' => [
                    'https://images.unsplash.com/photo-1519861531473-9200262188bf?w=800',
                ]
            ],
            [
                'category_id' => 4,
                'subcategory_id' => 25,
                'name' => 'Vélo Électrique 250W',
                'description' => 'VAE avec batterie 36V 10Ah, autonomie 60km, écran LCD et 7 vitesses Shimano. Parfait ville.',
                'price' => 585000,
                'stock' => 7,
                'weight_category' => 'Pallet',
                'images' => [
                    'https://images.unsplash.com/photo-1559348349-86f1f65817fe?w=800',
                ]
            ],
            [
                'category_id' => 4,
                'subcategory_id' => 26,
                'name' => 'Tente Camping 4 Personnes',
                'description' => 'Tente dôme imperméable avec double toit, moustiquaire et sac de transport. Montage facile 10min.',
                'price' => 125000,
                'stock' => 16,
                'weight_category' => '60 Deep',
                'images' => [
                    'https://images.unsplash.com/photo-1478131143081-80f7f84ca84d?w=800',
                ]
            ],
            [
                'category_id' => 4,
                'subcategory_id' => 26,
                'name' => 'Sac à Dos Randonnée 50L',
                'description' => 'Sac de trek avec armature dorsale, ceinture rembourrée, housse pluie et multiples poches. Ergonomique.',
                'price' => 95000,
                'stock' => 24,
                'weight_category' => '50 Deep',
                'images' => [
                    'https://images.unsplash.com/photo-1622260614153-03223fb72052?w=800',
                ]
            ],
            [
                'category_id' => 4,
                'subcategory_id' => 27,
                'name' => 'Raquette Tennis Wilson Pro',
                'description' => 'Raquette de tennis adulte avec cordage professionnel, poids 300g et grip confortable. Avec housse.',
                'price' => 78000,
                'stock' => 20,
                'weight_category' => '30 Deep',
                'images' => [
                    'https://images.unsplash.com/photo-1622279457486-62dcc4a431d6?w=800',
                ]
            ],
            [
                'category_id' => 4,
                'subcategory_id' => 28,
                'name' => 'Tapis de Yoga Premium',
                'description' => 'Tapis yoga antidérapant 6mm, écologique en TPE avec sac transport. Extra confort pour toutes pratiques.',
                'price' => 28000,
                'stock' => 48,
                'weight_category' => '30 Deep',
                'images' => [
                    'https://images.unsplash.com/photo-1601925260368-ae2f83cf8b7f?w=800',
                ]
            ],

            // More Beauty & Health - Category 5
            [
                'category_id' => 5,
                'subcategory_id' => 29,
                'name' => 'Sérum Vitamine C Anti-âge',
                'description' => 'Sérum facial haute concentration 20% vitamine C, acide hyaluronique et antioxydants. Résultats visibles.',
                'price' => 38000,
                'stock' => 52,
                'weight_category' => 'X-small',
                'images' => [
                    'https://images.unsplash.com/photo-1620916566398-39f1143ab7be?w=800',
                ]
            ],
            [
                'category_id' => 5,
                'subcategory_id' => 29,
                'name' => 'Crème Hydratante Bio Visage',
                'description' => 'Crème jour et nuit bio certifiée avec beurre de karité et huiles essentielles. Tous types de peau.',
                'price' => 32000,
                'stock' => 60,
                'weight_category' => 'X-small',
                'images' => [
                    'https://images.unsplash.com/photo-1571875257727-256c39da42af?w=800',
                ]
            ],
            [
                'category_id' => 5,
                'subcategory_id' => 30,
                'name' => 'Palette Maquillage 120 Couleurs',
                'description' => 'Coffret maquillage complet avec fards à paupières, blush, gloss et pinceaux. Qualité professionnelle.',
                'price' => 45000,
                'stock' => 35,
                'weight_category' => '30 Deep',
                'images' => [
                    'https://images.unsplash.com/photo-1512496015851-a90fb38ba796?w=800',
                ]
            ],
            [
                'category_id' => 5,
                'subcategory_id' => 30,
                'name' => 'Rouge à Lèvres Longue Tenue Set',
                'description' => 'Coffret 12 rouges à lèvres mate longue durée, formule enrichie en vitamines. Couleurs tendance.',
                'price' => 38000,
                'stock' => 42,
                'weight_category' => 'X-small',
                'images' => [
                    'https://images.unsplash.com/photo-1586495777744-4413f21062fa?w=800',
                ]
            ],
            [
                'category_id' => 5,
                'subcategory_id' => 31,
                'name' => 'Coffret Parfum Femme Luxe',
                'description' => 'Eau de parfum 100ml + lait corps parfumé 75ml. Notes florales et fruitées. Élégance intemporelle.',
                'price' => 125000,
                'stock' => 28,
                'weight_category' => 'X-small',
                'images' => [
                    'https://images.unsplash.com/photo-1563170351-be82bc888aa4?w=800',
                ]
            ],
            [
                'category_id' => 5,
                'subcategory_id' => 32,
                'name' => 'Kit Soin Cheveux Professionnel',
                'description' => 'Shampoing, après-shampoing et masque réparateur à la kératine. Pour cheveux abîmés et secs.',
                'price' => 48000,
                'stock' => 45,
                'weight_category' => '30 Deep',
                'images' => [
                    'https://images.unsplash.com/photo-1535585209827-a15fcdbc4c2d?w=800',
                ]
            ],
            [
                'category_id' => 5,
                'subcategory_id' => 33,
                'name' => 'Multivitamines Complètes 60 Gélules',
                'description' => 'Complément alimentaire avec vitamines, minéraux et antioxydants. Énergie et immunité renforcées.',
                'price' => 22000,
                'stock' => 75,
                'weight_category' => 'X-small',
                'images' => [
                    'https://images.unsplash.com/photo-1550572017-4fade735532e?w=800',
                ]
            ],
            [
                'category_id' => 5,
                'subcategory_id' => 34,
                'name' => 'Brosse à Dents Électrique Sonique',
                'description' => 'Brosse sonique rechargeable avec 5 modes, timer 2min et 8 têtes incluses. Blancheur garantie.',
                'price' => 58000,
                'stock' => 32,
                'weight_category' => 'X-small',
                'images' => [
                    'https://images.unsplash.com/photo-1607613009820-a29f7bb81c04?w=800',
                ]
            ],

            // More Books & Media - Category 6
            [
                'category_id' => 6,
                'subcategory_id' => 36,
                'name' => 'Romans Best-Sellers Pack 5 Livres',
                'description' => 'Collection 5 romans contemporains primés. Auteurs internationaux reconnus. Éditions récentes.',
                'price' => 35000,
                'stock' => 40,
                'weight_category' => '30 Deep',
                'images' => [
                    'https://images.unsplash.com/photo-1512820790803-83ca734da794?w=800',
                ]
            ],
            [
                'category_id' => 6,
                'subcategory_id' => 40,
                'name' => 'Console PlayStation 5 + 2 Jeux',
                'description' => 'PS5 Slim 1To avec deux jeux AAA inclus. Manette DualSense et câbles. Garantie constructeur.',
                'price' => 485000,
                'stock' => 6,
                'weight_category' => '60 Deep',
                'images' => [
                    'https://images.unsplash.com/photo-1606144042614-b2417e99c4e3?w=800',
                ]
            ],
            [
                'category_id' => 6,
                'subcategory_id' => 40,
                'name' => 'Manette Xbox Sans Fil Elite',
                'description' => 'Manette premium personnalisable avec sticks interchangeables, grips et étui rigide de transport.',
                'price' => 125000,
                'stock' => 18,
                'weight_category' => 'X-small',
                'images' => [
                    'https://images.unsplash.com/photo-1585504198199-20277593b94f?w=800',
                ]
            ],

            // More Toys - Category 7
            [
                'category_id' => 7,
                'subcategory_id' => 42,
                'name' => 'Jouets Éveil Bébé Set 8 Pièces',
                'description' => 'Hochets, anneaux dentition et peluches d\'éveil sans BPA. Couleurs vives et textures variées.',
                'price' => 28000,
                'stock' => 45,
                'weight_category' => 'X-small',
                'images' => [
                    'https://images.unsplash.com/photo-1515488042361-ee00e0ddd4e4?w=800',
                ]
            ],
            [
                'category_id' => 7,
                'subcategory_id' => 43,
                'name' => 'Circuit Voitures Hot Wheels',
                'description' => 'Piste de course avec loopings, lanceur et 2 voitures. Extensible avec autres sets. Âge 5+.',
                'price' => 52000,
                'stock' => 30,
                'weight_category' => '50 Deep',
                'images' => [
                    'https://images.unsplash.com/photo-1558060370-d644479cb6f7?w=800',
                ]
            ],
            [
                'category_id' => 7,
                'subcategory_id' => 45,
                'name' => 'Monopoly Édition Classique',
                'description' => 'Jeu de société emblématique pour 2-6 joueurs. Édition traditionnelle avec pions métalliques.',
                'price' => 35000,
                'stock' => 38,
                'weight_category' => '30 Deep',
                'images' => [
                    'https://images.unsplash.com/photo-1611891487928-fc64f37dbfd4?w=800',
                ]
            ],
            [
                'category_id' => 7,
                'subcategory_id' => 47,
                'name' => 'Figurines Marvel Avengers Set',
                'description' => 'Pack 6 figurines articulées 15cm: Iron Man, Captain America, Thor, Hulk, Spider-Man, Black Panther.',
                'price' => 68000,
                'stock' => 25,
                'weight_category' => '30 Deep',
                'images' => [
                    'https://images.unsplash.com/photo-1608889825146-fea1354d3fb4?w=800',
                ]
            ],

            // More Food & Beverages - Category 8
            [
                'category_id' => 8,
                'subcategory_id' => 49,
                'name' => 'Huile d\'Olive Extra Vierge 1L',
                'description' => 'Huile d\'olive première pression à froid, origine Tunisie. Riche en oméga-3. Bouteille verre.',
                'price' => 18000,
                'stock' => 80,
                'weight_category' => '30 Deep',
                'images' => [
                    'https://images.unsplash.com/photo-1474979266404-7eaacbcd87c5?w=800',
                ]
            ],
            [
                'category_id' => 8,
                'subcategory_id' => 49,
                'name' => 'Riz Basmati Premium 5kg',
                'description' => 'Riz basmati blanc extra-long grain, origine Inde. Parfait pour plats traditionnels. Sachet refermable.',
                'price' => 22000,
                'stock' => 65,
                'weight_category' => '50 Deep',
                'images' => [
                    'https://images.unsplash.com/photo-1586201375761-83865001e31c?w=800',
                ]
            ],
            [
                'category_id' => 8,
                'subcategory_id' => 50,
                'name' => 'Mix Fruits Secs Premium 1kg',
                'description' => 'Mélange noix, amandes, cajou, raisins et cranberries. Sans additifs. Parfait snacking santé.',
                'price' => 35000,
                'stock' => 55,
                'weight_category' => '30 Deep',
                'images' => [
                    'https://images.unsplash.com/photo-1599599810769-bcde5a160d32?w=800',
                ]
            ],
            [
                'category_id' => 8,
                'subcategory_id' => 51,
                'name' => 'Jus Naturels Détox Pack 6x1L',
                'description' => 'Assortiment jus de fruits frais pressés sans sucre ajouté. Orange, pomme, carotte et mélanges.',
                'price' => 42000,
                'stock' => 35,
                'weight_category' => '60 Deep',
                'images' => [
                    'https://images.unsplash.com/photo-1600271886742-f049cd451bba?w=800',
                ]
            ],
            [
                'category_id' => 8,
                'subcategory_id' => 53,
                'name' => 'Thé Vert Bio Collection 50 Sachets',
                'description' => 'Assortiment thés verts biologiques: nature, menthe, jasmin, citron. Antioxydants naturels.',
                'price' => 15000,
                'stock' => 70,
                'weight_category' => 'X-small',
                'images' => [
                    'https://images.unsplash.com/photo-1556881286-fc6915169721?w=800',
                ]
            ],

            // More Automotive - Category 9
            [
                'category_id' => 9,
                'subcategory_id' => 55,
                'name' => 'Kit Filtres Entretien Auto',
                'description' => 'Set complet: filtre à huile, air, habitacle et carburant. Compatible plusieurs modèles. Qualité OEM.',
                'price' => 48000,
                'stock' => 40,
                'weight_category' => '30 Deep',
                'images' => [
                    'https://images.unsplash.com/photo-1486262715619-67b85e0b08d3?w=800',
                ]
            ],
            [
                'category_id' => 9,
                'subcategory_id' => 56,
                'name' => 'Organiseur Coffre Voiture Pliable',
                'description' => 'Rangement coffre 3 compartiments avec poches latérales. Pliable et lavable. Grande capacité.',
                'price' => 22000,
                'stock' => 55,
                'weight_category' => '30 Deep',
                'images' => [
                    'https://images.unsplash.com/photo-1449965408869-eaa3f722e40d?w=800',
                ]
            ],
            [
                'category_id' => 9,
                'subcategory_id' => 58,
                'name' => 'Pneu Michelin 205/55 R16',
                'description' => 'Pneu toutes saisons avec technologie silence et adhérence renforcée. Longévité 50000km.',
                'price' => 85000,
                'stock' => 30,
                'weight_category' => 'Rainbow XL',
                'images' => [
                    'https://images.unsplash.com/photo-1593941707882-a5bba14938c7?w=800',
                ]
            ],
            [
                'category_id' => 9,
                'subcategory_id' => 60,
                'name' => 'Autoradio GPS Android CarPlay',
                'description' => 'Écran tactile 7 pouces avec GPS, Bluetooth, USB et caméra recul. Compatible Android Auto et CarPlay.',
                'price' => 145000,
                'stock' => 15,
                'weight_category' => '30 Deep',
                'images' => [
                    'https://images.unsplash.com/photo-1521657693946-0e9c95696aba?w=800',
                ]
            ],

            // More Pet Supplies - Category 10
            [
                'category_id' => 10,
                'subcategory_id' => 61,
                'name' => 'Croquettes Chien Premium 15kg',
                'description' => 'Alimentation complète pour chien adulte avec viande fraîche, légumes et vitamines. Sans céréales.',
                'price' => 68000,
                'stock' => 35,
                'weight_category' => 'Rainbow XL',
                'images' => [
                    'https://images.unsplash.com/photo-1589924691995-400dc9ecc119?w=800',
                ]
            ],
            [
                'category_id' => 10,
                'subcategory_id' => 62,
                'name' => 'Arbre à Chat Multi-niveaux',
                'description' => 'Tour à chat 150cm avec griffoirs, hamacs, niches et jouets suspendus. Stable et design.',
                'price' => 95000,
                'stock' => 12,
                'weight_category' => 'Pallet',
                'images' => [
                    'https://images.unsplash.com/photo-1545249390-6bdfa286032f?w=800',
                ]
            ],
            [
                'category_id' => 10,
                'subcategory_id' => 62,
                'name' => 'Litière Chat Agglomérante 10L',
                'description' => 'Litière bentonite ultra-absorbante avec contrôle odeurs. Formation boules pour nettoyage facile.',
                'price' => 15000,
                'stock' => 85,
                'weight_category' => '50 Deep',
                'images' => [
                    'https://images.unsplash.com/photo-1587300003388-59208cc962cb?w=800',
                ]
            ],
            [
                'category_id' => 10,
                'subcategory_id' => 64,
                'name' => 'Aquarium Complet 100L LED',
                'description' => 'Aquarium avec filtre, chauffage, éclairage LED et décoration. Prêt à accueillir poissons.',
                'price' => 185000,
                'stock' => 8,
                'weight_category' => 'Pallet',
                'images' => [
                    'https://images.unsplash.com/photo-1520990269108-4f2ca14e8f3b?w=800',
                ]
            ],

            // More Office & Stationery - Category 11
            [
                'category_id' => 11,
                'subcategory_id' => 67,
                'name' => 'Set Stylos Luxe 3 Pièces',
                'description' => 'Coffret stylo plume, roller et bille en métal avec gravure. Coffret cadeau élégant.',
                'price' => 45000,
                'stock' => 25,
                'weight_category' => 'X-small',
                'images' => [
                    'https://images.unsplash.com/photo-1585366119957-e9730b6d0f60?w=800',
                ]
            ],
            [
                'category_id' => 11,
                'subcategory_id' => 68,
                'name' => 'Cartable Scolaire Ergonomique',
                'description' => 'Sac à dos scolaire avec bretelles rembourrées, dos aéré et multiples compartiments. Résistant.',
                'price' => 38000,
                'stock' => 50,
                'weight_category' => '30 Deep',
                'images' => [
                    'https://images.unsplash.com/photo-1553062407-98eeb64c6a62?w=800',
                ]
            ],
            [
                'category_id' => 11,
                'subcategory_id' => 70,
                'name' => 'Imprimante Laser HP Pro',
                'description' => 'Imprimante laser noir & blanc avec WiFi, recto-verso auto et vitesse 40ppm. Économique.',
                'price' => 285000,
                'stock' => 10,
                'weight_category' => '60 Deep',
                'images' => [
                    'https://images.unsplash.com/photo-1612815154858-60aa4c59eaa6?w=800',
                ]
            ],
            [
                'category_id' => 11,
                'subcategory_id' => 71,
                'name' => 'Organiseur Bureau 6 Compartiments',
                'description' => 'Range-documents en bois avec tiroirs, porte-stylos et compartiments. Design moderne et pratique.',
                'price' => 32000,
                'stock' => 35,
                'weight_category' => '30 Deep',
                'images' => [
                    'https://images.unsplash.com/photo-1600857062241-98e5e6300144?w=800',
                ]
            ],

            // More Baby & Childcare - Category 12
            [
                'category_id' => 12,
                'subcategory_id' => 75,
                'name' => 'Poussette 3-en-1 Complète',
                'description' => 'Poussette convertible: nacelle, siège-auto et assise. Pliage compact, suspension et grand panier.',
                'price' => 385000,
                'stock' => 6,
                'weight_category' => 'Pallet',
                'images' => [
                    'https://images.unsplash.com/photo-1544367567-0f2fcb009e0b?w=800',
                ]
            ],
            [
                'category_id' => 12,
                'subcategory_id' => 76,
                'name' => 'Couches Pampers Taille 4 Pack 120',
                'description' => 'Couches ultra-absorbantes avec canaux d\'air et barrières anti-fuites. Douceur testée.',
                'price' => 28000,
                'stock' => 95,
                'weight_category' => '60 Deep',
                'images' => [
                    'https://images.unsplash.com/photo-1515488042361-ee00e0ddd4e4?w=800',
                ]
            ],
            [
                'category_id' => 12,
                'subcategory_id' => 77,
                'name' => 'Lit Bébé Évolutif avec Matelas',
                'description' => 'Lit évolutif 60x120cm transformable, barreaux réglables et matelas confort inclus. Bois massif.',
                'price' => 195000,
                'stock' => 8,
                'weight_category' => 'Pallet',
                'images' => [
                    'https://images.unsplash.com/photo-1522771739844-6a9f6d5f14af?w=800',
                ]
            ],

            // More Home Appliances - Category 14
            [
                'category_id' => 14,
                'subcategory_id' => 85,
                'name' => 'Réfrigérateur LG 400L No Frost',
                'description' => 'Réfrigérateur-congélateur avec technologie No Frost, éclairage LED et classe énergétique A++.',
                'price' => 485000,
                'stock' => 5,
                'weight_category' => 'Pallet',
                'images' => [
                    'https://images.unsplash.com/photo-1571175443880-49e1d25b2bc5?w=800',
                ]
            ],
            [
                'category_id' => 14,
                'subcategory_id' => 85,
                'name' => 'Lave-linge Hublot 8kg Samsung',
                'description' => 'Machine à laver avec tambour 8kg, essorage 1400tr/min, 14 programmes et départ différé.',
                'price' => 395000,
                'stock' => 7,
                'weight_category' => 'Pallet',
                'images' => [
                    'https://images.unsplash.com/photo-1626806819282-2c1dc01a5e0c?w=800',
                ]
            ],
            [
                'category_id' => 14,
                'subcategory_id' => 86,
                'name' => 'Micro-ondes Grill 30L',
                'description' => 'Four micro-ondes avec fonction grill, plateau tournant 31,5cm et 10 niveaux de puissance.',
                'price' => 95000,
                'stock' => 20,
                'weight_category' => '60 Deep',
                'images' => [
                    'https://images.unsplash.com/photo-1585659722983-3a675dabf23d?w=800',
                ]
            ],
            [
                'category_id' => 14,
                'subcategory_id' => 87,
                'name' => 'Aspirateur Robot Intelligent',
                'description' => 'Robot aspirateur avec navigation laser, aspiration 2700Pa, app contrôle et retour auto base.',
                'price' => 285000,
                'stock' => 12,
                'weight_category' => '50 Deep',
                'images' => [
                    'https://images.unsplash.com/photo-1558317374-067fb5f30001?w=800',
                ]
            ],
            [
                'category_id' => 14,
                'subcategory_id' => 88,
                'name' => 'Climatiseur Split 12000 BTU',
                'description' => 'Climatiseur inverter silencieux avec télécommande, programmation et mode déshumidification.',
                'price' => 385000,
                'stock' => 9,
                'weight_category' => 'Pallet',
                'images' => [
                    'https://images.unsplash.com/photo-1585909695284-32d2985ac9c0?w=800',
                ]
            ],
        ];

        foreach ($products as $productData) {
            $images = $productData['images'];
            unset($productData['images']);

            // Generate random coordinates around Yaoundé (latitude: 3.8480, longitude: 11.5021)
            // Adding random offset between -0.02 and +0.02 (~2km radius)
            $baseLatitude = 3.8480;
            $baseLongitude = 11.5021;
            $latitude = $baseLatitude + (mt_rand(-200, 200) / 10000);
            $longitude = $baseLongitude + (mt_rand(-200, 200) / 10000);

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
                'latitude' => $latitude,
                'longitude' => $longitude,
                'price_type' => 'fixed',
                'type' => 'article',
                'status' => 'active',
            ]);

            // Create initial inventory entry for the product
            Inventory::create([
                'product_id' => $product->id,
                'user_id' => $userId,
                'type' => 'entry',
                'quantity' => $productData['stock'],
                'stock_after' => $productData['stock'],
                'notes' => 'Stock initial - Importation produits',
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

        $this->command->info('Extended products seeded successfully with inventory entries!');
    }
}
