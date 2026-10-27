<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Corriger uniquement les valeurs de l'ancien catalogue fourni.
        // Les tarifs personnalisés et les snapshots des départs restent intacts.
        $partners = DB::table('deliverer_companies')->where('name', 'SinoCam Transit & Fret')->where('service_type', 'maritime')->pluck('id');
        foreach ([['general', 330000, 450000], ['sensitive', 390000, 500000]] as [$category, $cost, $public]) {
            DB::table('partner_freight_rates')->whereIn('deliverer_company_id', $partners)
                ->where('origin_country', 'CN')->where('destination_country', 'CM')->where('transport_mode', 'sea')
                ->where('cargo_category', $category)->where('rate_unit', 'cbm')->where('currency', 'XAF')
                ->where('rate_amount', $cost)->where('public_rate_amount', $public)
                ->update(['rate_amount' => 650, 'public_rate_amount' => 900, 'currency' => 'EUR', 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        // Une annulation de schéma ne doit pas rétablir des prix commerciaux périmés.
    }
};
