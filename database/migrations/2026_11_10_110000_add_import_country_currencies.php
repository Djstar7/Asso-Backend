<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Devises des pays grossistes, pour saisir un prix tel que sur le devis fournisseur :
 * yuan (Chine), dirham (Dubaï), livre turque. Les taux viennent de l'API de change
 * (ExchangeRateService) ; sans taux, la commande est refusée plutôt que mal convertie.
 */
return new class extends Migration
{
    private const CURRENCIES = [
        ['code' => 'CNY', 'name' => 'Yuan chinois', 'symbol' => '¥', 'countries' => ['Chine']],
        ['code' => 'AED', 'name' => 'Dirham des Émirats', 'symbol' => 'AED', 'countries' => ['Émirats arabes unis']],
        ['code' => 'TRY', 'name' => 'Livre turque', 'symbol' => '₺', 'countries' => ['Turquie']],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('currencies')) {
            return;
        }
        foreach (self::CURRENCIES as $currency) {
            $exists = DB::table('currencies')->where('code', $currency['code'])->exists();
            DB::table('currencies')->updateOrInsert(['code' => $currency['code']], [
                'name' => $currency['name'],
                'symbol' => $currency['symbol'],
                'countries' => json_encode($currency['countries'], JSON_UNESCAPED_UNICODE),
                'is_active' => true,
                'updated_at' => now(),
            ] + ($exists ? [] : ['created_at' => now()]));
        }
    }

    public function down(): void
    {
        // Des produits ou paliers peuvent déjà utiliser ces devises : on les garde.
    }
};
