<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Catalogue de départ des atouts, équipements et services immobiliers.
 * Il reste modifiable dans le back-office (nom FR/EN, icône, ordre, actif).
 * Les icônes sont des noms Material que l'application sait afficher.
 */
return new class extends Migration
{
    private const ITEMS = [
        // [kind, code, fr, en, icon]
        ['highlight', 'private_bathroom', 'Douche privée', 'Private bathroom', 'bathtub'],
        ['highlight', 'internet', 'Connexion internet', 'Internet connection', 'wifi'],
        ['highlight', 'tiled', 'Carrelage', 'Tiled floors', 'grid_view'],
        ['highlight', 'ceiling', 'Plafond', 'Ceiling', 'roofing'],
        ['highlight', 'new_build', 'Construction récente', 'Recently built', 'new_releases'],
        ['highlight', 'quiet_area', 'Quartier calme', 'Quiet area', 'nights_stay'],
        ['equipment', 'water_tank', 'Réserve d\'eau', 'Water tank', 'water_drop'],
        ['equipment', 'borehole', 'Forage', 'Borehole', 'water'],
        ['equipment', 'generator', 'Groupe électrogène', 'Generator', 'bolt'],
        ['equipment', 'solar', 'Panneaux solaires', 'Solar panels', 'solar_power'],
        ['equipment', 'air_conditioning', 'Climatisation', 'Air conditioning', 'ac_unit'],
        ['equipment', 'fan', 'Ventilateur', 'Ceiling fan', 'mode_fan_off'],
        ['equipment', 'water_heater', 'Chauffe-eau', 'Water heater', 'hot_tub'],
        ['equipment', 'kitchen', 'Cuisine équipée', 'Equipped kitchen', 'kitchen'],
        ['equipment', 'fridge', 'Réfrigérateur', 'Fridge', 'kitchen'],
        ['equipment', 'tv', 'Télévision', 'TV', 'tv'],
        ['equipment', 'bed_linen', 'Linge de lit', 'Bed linen', 'bed'],
        ['equipment', 'wardrobe', 'Placard', 'Wardrobe', 'door_sliding'],
        ['equipment', 'balcony', 'Balcon', 'Balcony', 'balcony'],
        ['equipment', 'garden', 'Jardin ou cour', 'Garden or yard', 'yard'],
        ['equipment', 'parking', 'Parking', 'Parking', 'local_parking'],
        ['service', 'guard', 'Gardien', 'Caretaker', 'security'],
        ['service', 'cctv', 'Caméra de surveillance', 'Security camera', 'videocam'],
        ['service', 'gate', 'Portail sécurisé', 'Secure gate', 'fence'],
        ['service', 'cleaning', 'Ménage des parties communes', 'Common areas cleaning', 'cleaning_services'],
        ['service', 'garbage', 'Ramassage des ordures', 'Garbage collection', 'delete'],
        ['service', 'on_site_manager', 'Gestionnaire sur place', 'On-site manager', 'support_agent'],
    ];

    public function up(): void
    {
        $now = now();
        foreach (self::ITEMS as $position => [$kind, $code, $fr, $en, $icon]) {
            if (DB::table('real_estate_amenities')->where('code', $code)->exists()) {
                continue;
            }
            $id = DB::table('real_estate_amenities')->insertGetId([
                'kind' => $kind, 'code' => $code, 'name' => $fr, 'icon' => $icon,
                'position' => $position, 'is_active' => true,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            DB::table('translations')->insert([
                'translatable_type' => \App\Models\RealEstate\Amenity::class,
                'translatable_id' => $id, 'locale' => 'en', 'field' => 'name', 'value' => $en,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        $ids = DB::table('real_estate_amenities')->whereIn('code', array_column(self::ITEMS, 1))->pluck('id');
        DB::table('translations')->where('translatable_type', \App\Models\RealEstate\Amenity::class)
            ->whereIn('translatable_id', $ids)->delete();
        DB::table('real_estate_amenities')->whereIn('id', $ids)->delete();
    }
};
