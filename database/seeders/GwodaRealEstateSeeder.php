<?php

namespace Database\Seeders;

use App\Models\RealEstate\Cabinet;
use App\Models\RealEstate\Listing;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Import de production explicite : php artisan db:seed --class=GwodaRealEstateSeeder --force
 *
 * Les sept ventes utilisent le parcours foncier existant (type land), y compris
 * les deux immeubles vendus en bloc, décrits dans le texte. Aucun loyer ni
 * logement vendu séparément n'est créé. Les prix au m² deviennent des prix de
 * lot NETS ; la majoration publique reste celle du paramétrage de l'application.
 * Hypothèse : les 12 000 FCFA de Nkolfoulou sont un prix au m².
 *
 * Après import : ajouter les photos dans Immobilier > Annonces, puis Publier.
 * Pas de faux médias : les quantités attendues sont affichées dans la console.
 * Une relance conserve toute annonce existante, même supprimée, et ses médias.
 */
class GwodaRealEstateSeeder extends Seeder
{
    public function run(): void
    {
        $rows = DB::transaction(function () {
            $cabinet = $this->cabinet();
            $rows = [];

            foreach ($this->listings() as $data) {
                $photos = $data['photos'];
                unset($data['photos']);
                $listing = Listing::withTrashed()->where('reference', $data['reference'])->first();

                if ($listing && (int) $listing->cabinet_id !== (int) $cabinet->id) {
                    throw new RuntimeException("La référence {$data['reference']} appartient à un autre cabinet. Import annulé.");
                }

                $created = ! $listing;
                if ($created) {
                    $listing = Listing::create($data + [
                        'type' => Listing::TYPE_LAND,
                        'property_kind' => 'building_land',
                        'cabinet_id' => $cabinet->id,
                        'country' => 'CM',
                        'city' => 'Yaoundé',
                        'status' => Listing::STATUS_DRAFT,
                    ]);
                    $listing->units()->create([
                        'label' => 'Vente en bloc — '.number_format($data['surface_m2'], 0, ',', ' ').' m²',
                        'surface_min' => $data['surface_m2'],
                        'surface_max' => $data['surface_m2'],
                        'net_price' => $data['net_price'],
                        'units_total' => 1,
                        'units_available' => 1,
                        'position' => 0,
                    ]);
                }

                $rows[] = [$listing->reference, $listing->title, $photos, $created ? 'Créée en brouillon' : 'Existante conservée'];
            }

            return $rows;
        });

        $this->command?->table(['Référence', 'Annonce', 'Photos attendues', 'Résultat'], $rows);
        $this->command?->info('GWODA : ajoutez les 29 photos dans le back-office, puis publiez les annonces.');
        $this->command?->warn('Nkolfoulou : 12 000 FCFA/m² supposés, soit 3 600 000 FCFA. Prix enregistrés nets, avant majoration ASSO.');
    }

    private function cabinet(): Cabinet
    {
        // Réutilise aussi « Cabinet Gwoda et Associés », sans renommer son compte.
        $matches = Cabinet::whereRaw('LOWER(name) LIKE ?', ['%gwoda%'])->get()
            ->filter(fn (Cabinet $cabinet) => preg_match('/\bgwoda\b/i', $cabinet->name));
        if ($matches->count() > 1) {
            throw new RuntimeException('Plusieurs cabinets GWODA existent : résolvez le doublon avant cet import.');
        }

        $cabinet = $matches->first() ?? Cabinet::create([
            'name' => 'GWODA', 'is_active' => true, 'is_verified' => false,
        ]);
        if (! $cabinet->is_active) {
            throw new RuntimeException('Le cabinet GWODA est inactif. Import annulé.');
        }

        // Comme dans la création admin, le compte sert de bénéficiaire aux ventes.
        // Aucun contact ni mot de passe public n'est inventé.
        if (! $cabinet->user_id) {
            $user = $cabinet->email ? User::where('email', $cabinet->email)->first() : null;
            if (! $user) {
                $user = new User;
                $user->forceFill([
                    'first_name' => Str::limit($cabinet->name, 60, ''),
                    'last_name' => 'Cabinet',
                    'email' => $cabinet->email ?: 'cabinet-'.Str::lower(Str::random(16)).'@partners.asso.app',
                    'password' => Hash::make(Str::random(32)),
                ])->save();
            }
            $cabinet->update(['user_id' => $user->id]);
        }

        return $cabinet;
    }

    private function listings(): array
    {
        return [
            [
                'reference' => 'GWODA-001',
                'title' => 'Immeuble R+1 à vendre à Awae Carrière — 300 m²',
                'district' => 'Awae Carrière',
                'surface_m2' => 300,
                'net_price' => 40000000,
                'land_titled' => true,
                'land_title_status' => 'Dans le titre mère',
                'owner_description' => "Immeuble R+1 à vendre à Awae Carrière, Yaoundé, sur une parcelle de 300 m² dans le titre mère.\n\nAu rez-de-chaussée : un appartement de 3 chambres, 2 douches, un salon, une cuisine et une salle à manger. À l'étage : 2 studios modernes. Seul le rez-de-chaussée est habitable ; il ne manque que la peinture.\n\nAccès : à 300 FCFA de moto du carrefour Awae.\nPrix de vente de l'ensemble : 40 000 000 FCFA.",
                'photos' => 6,
            ],
            [
                'reference' => 'GWODA-002',
                'title' => 'Terrain titré morcelable à Nyom — 3 000 m²',
                'district' => 'Nyom',
                'address' => 'Derrière le lycée de Nyom',
                'surface_m2' => 3000,
                'net_price' => 3000 * 20000,
                'land_titled' => true,
                'land_title_status' => 'Titré',
                'owner_description' => "Terrain titré de 3 000 m² à Nyom, derrière le lycée. Terrain plat, accessible et morcelable.\n\nPrix : 20 000 FCFA/m², soit 60 000 000 FCFA pour la totalité. Le morcellement est possible ; les surfaces des lots restent à convenir avec le cabinet.",
                'photos' => 4,
            ],
            [
                'reference' => 'GWODA-003',
                'title' => 'Terrain titré à Petou Nkolnda — 1 200 m²',
                'district' => 'Petou Nkolnda',
                'surface_m2' => 1200,
                'net_price' => 1200 * 40000,
                'land_titled' => true,
                'land_title_status' => 'Titré',
                'road_access' => 'near_road',
                'distance_to_road_m' => 100,
                'owner_description' => "Terrain titré de 1 200 m² à Petou Nkolnda. Terrain plat situé à 100 mètres de l'axe principal.\n\nPrix : 40 000 FCFA/m², soit 48 000 000 FCFA pour l'ensemble.",
                'photos' => 5,
            ],
            [
                'reference' => 'GWODA-004',
                'title' => 'Vaste immeuble à vendre à Mbalngong Simbock — 360 m²',
                'district' => 'Mbalngong Simbock',
                'surface_m2' => 360,
                'net_price' => 175000000,
                'land_titled' => true,
                'land_title_status' => 'Titré',
                'owner_description' => "Vaste immeuble à vendre à Mbalngong Simbock, sur une parcelle titrée de 360 m².\n\nL'ensemble comprend une villa ultra moderne, 6 appartements ultra modernes et 3 studios modernes.\n\nPrix de vente de l'ensemble : 175 000 000 FCFA.",
                'photos' => 3,
            ],
            [
                'reference' => 'GWODA-005',
                'title' => 'Terrain titré avec fondation de 3 studios à Simbock — 500 m²',
                'district' => 'Simbock',
                'surface_m2' => 500,
                'net_price' => 500 * 90000,
                'land_titled' => true,
                'land_title_status' => 'Titré',
                'road_access' => 'near_road',
                'distance_to_road_m' => 100,
                'owner_description' => "Terrain titré de 500 m² à Simbock, à 100 mètres de l'axe principal. Terrain plat avec fondation de 3 studios et deux entrées. Bien placé pour la construction de logements locatifs ou résidentiels.\n\nPrix : 90 000 FCFA/m², soit 45 000 000 FCFA pour l'ensemble.",
                'photos' => 4,
            ],
            [
                'reference' => 'GWODA-006',
                'title' => 'Terrain titré en bord de route à Simbock, derrière le camp SIC — 600 m²',
                'district' => 'Simbock',
                'address' => 'Derrière le camp SIC, au bord de la route',
                'surface_m2' => 600,
                'net_price' => 600 * 75000,
                'land_titled' => true,
                'land_title_status' => 'Titré',
                'road_access' => 'roadside',
                'distance_to_road_m' => 0,
                'owner_description' => "Terrain titré de 600 m² à Simbock, derrière le camp SIC, au bord de la route. Convient à un projet d'habitation ou commercial.\n\nPrix : 75 000 FCFA/m², soit 45 000 000 FCFA pour l'ensemble.",
                'photos' => 4,
            ],
            [
                'reference' => 'GWODA-007',
                'title' => 'Terrain à vendre au lieu-dit stade Benita, Nkolfoulou — 300 m²',
                'district' => 'Nkolfoulou',
                'address' => 'Lieu-dit stade Benita',
                'surface_m2' => 300,
                'net_price' => 300 * 12000,
                'owner_description' => "Terrain de 300 m² au lieu-dit stade Benita, à 200 FCFA de transport du carrefour Nkolfoulou.\n\nPrix retenu pour l'annonce : 12 000 FCFA/m², soit 3 600 000 FCFA pour l'ensemble. Le statut du titre foncier n'a pas été communiqué.",
                'photos' => 3,
            ],
        ];
    }
}
