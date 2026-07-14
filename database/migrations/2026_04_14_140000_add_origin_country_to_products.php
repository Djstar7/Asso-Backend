<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pays d'origine du produit (pour la section « Produits importés » :
 * Chine, Turquie, Dubaï…). Code ISO 3166-1 alpha-2 (CN, TR, AE).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (!Schema::hasColumn('products', 'origin_country')) {
                $table->string('origin_country', 2)->nullable()->index()->after('type');
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (Schema::hasColumn('products', 'origin_country')) {
                $table->dropColumn('origin_country');
            }
        });
    }
};
