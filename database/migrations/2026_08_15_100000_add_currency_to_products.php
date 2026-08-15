<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * [2b] Prix produit multi-devises.
 *
 * - currency  : devise dans laquelle le VENDEUR a fixé le prix (source de vérité).
 *               Défaut 'XAF' → rétro-compatible avec l'existant (tout était XAF-natif).
 * - price_xaf : valeur canonique du prix convertie en XAF, calculée à l'écriture.
 *               Sert au tri/filtre et aux requêtes côté plateforme (XAF-pivot).
 *               NB : le montant réellement débité à l'achat est reconverti au taux
 *               du moment de la commande (price_xaf n'est qu'un cache indicatif).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('currency', 3)->default('XAF')->after('price');
            $table->decimal('price_xaf', 20, 2)->nullable()->after('currency');
        });

        // Backfill : produits existants = déjà en XAF, price_xaf = price.
        DB::table('products')->whereNull('price_xaf')->update([
            'price_xaf' => DB::raw('price'),
            'currency' => 'XAF',
        ]);
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['currency', 'price_xaf']);
        });
    }
};
