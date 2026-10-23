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
 * Import explicite de production :
 * php artisan db:seed --class=AudyHomeRealEstateSeeder --force
 *
 * Une annonce pour le domaine de 2,5 ha, avec un seul lot commercial de
 * 1 000 m² au prix net de 2 500 000 FCFA. Aucun découpage en 25 parcelles
 * n'est supposé : les lots réels restent à confirmer avec le plan de lotissement.
 * Le domaine entier n'est pas proposé au prix du lot minimum.
 *
 * Après import : ajouter les photos depuis le back-office, puis publier.
 * Une relance conserve les annonces existantes, même supprimées, sans modifier
 * les prix, le stock, les médias, les bénéficiaires ou le cabinet par défaut.
 */
class AudyHomeRealEstateSeeder extends Seeder
{
    private const REFERENCE = 'AUDY-HOME-001';

    public function run(): void
    {
        $created = DB::transaction(function () {
            $cabinet = $this->cabinet();
            $listing = Listing::withTrashed()->where('reference', self::REFERENCE)->first();
            if ($listing) {
                if ((int) $listing->cabinet_id !== (int) $cabinet->id) {
                    throw new RuntimeException('La référence '.self::REFERENCE.' appartient à un autre cabinet. Import annulé.');
                }

                return false;
            }

            $listing = Listing::create([
                'reference' => self::REFERENCE,
                'type' => Listing::TYPE_LAND,
                'property_kind' => 'building_land',
                'cabinet_id' => $cabinet->id,
                'title' => 'Terrain titré à Ngolié–Castongo — dès 1 000 m²',
                'country' => 'CM',
                'city' => 'Ngolié',
                'district' => 'Castongo',
                'address' => 'Lieu-dit Castongo, après Afanoyoa',
                'surface_m2' => 25000,
                'land_titled' => true,
                'land_title_status' => 'Titré et loti',
                'net_price' => 1000 * 2500,
                'status' => Listing::STATUS_DRAFT,
                'owner_description' => "Domaine titré et loti de 2,5 hectares (25 000 m²) situé à Ngolié, au lieu-dit Castongo, après Afanoyoa, à proximité de Yaoundé.\n\nParcelles proposées à partir de 1 000 m², au prix net de 2 500 FCFA/m² : le lot minimum de 1 000 m² revient à 2 500 000 FCFA. La superficie totale du domaine ne correspond pas à celle du lot minimum ; les surfaces et disponibilités des lots sont à confirmer sur le plan de lotissement.\n\nAccès par plusieurs routes aménagées, annoncées praticables en toute saison. Repères indiqués : environ 1 km du lycée de Ngolié, 16 km du carrefour Afanoyoa et 14 km de Total Mobile Yaos.\n\nLe domaine est proposé pour des projets agricoles, agro-industriels, fonciers ou immobiliers, selon les possibilités du site.\n\nAccompagnement proposé : documentation foncière disponible, intervention d'un géomètre pour l'implantation et la préparation du dossier technique. Transactions devant notaire.",
            ]);
            $listing->units()->create([
                'label' => 'Parcelle de 1 000 m² — lot minimum',
                'surface_min' => 1000,
                'surface_max' => 1000,
                'net_price' => 1000 * 2500,
                'units_total' => 1,
                'units_available' => 1,
                'position' => 0,
            ]);

            return true;
        });

        $this->command?->info(self::REFERENCE.($created ? ' : annonce AUDY HOME créée en brouillon.' : ' : annonce existante conservée (y compris si supprimée).'));
        $this->command?->info('Domaine : 25 000 m². Lot minimum : 1 000 m² à 2 500 000 FCFA nets, avant majoration ASSO.');
        $this->command?->info('Ajoutez les photos, puis publiez depuis le back-office. Aucun média ni contact fictif ajouté.');
    }

    private function cabinet(): Cabinet
    {
        $matches = Cabinet::withTrashed()->whereRaw('LOWER(name) LIKE ?', ['%audy%home%'])
            ->lockForUpdate()->get()
            ->filter(fn (Cabinet $cabinet) => preg_match('/\baudy[\s-]+home\b/iu', $cabinet->name));
        if ($matches->count() > 1) {
            throw new RuntimeException('Plusieurs cabinets AUDY HOME existent. Résolvez le doublon avant cet import.');
        }
        $cabinet = $matches->first() ?? Cabinet::create([
            'name' => 'AUDY HOME', 'is_active' => true, 'is_verified' => false,
        ]);
        if ($cabinet->trashed() || ! $cabinet->is_active) {
            throw new RuntimeException('Le cabinet AUDY HOME est supprimé ou inactif. Import annulé.');
        }
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
}
