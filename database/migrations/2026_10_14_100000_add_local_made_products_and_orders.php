<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Produit local (fabriqué au Cameroun), distinct du « Produit importé » (origine vide,
 * ancien « Produit local ») et des grossistes (origin_country). Aucune donnée existante
 * n'est modifiée : tout reste « Produit importé » tant qu'on ne coche pas « Produit local ».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('is_local_made')->default(false)->after('origin_country')->index();
        });

        // Figé à la commande : alimente le menu « Commandes locales » du Dashboard.
        Schema::table('orders', function (Blueprint $table) {
            $table->boolean('has_local_products')->default(false)->after('is_wholesale')->index();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['has_local_products']);
            $table->dropColumn('has_local_products');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['is_local_made']);
            $table->dropColumn('is_local_made');
        });
    }
};
