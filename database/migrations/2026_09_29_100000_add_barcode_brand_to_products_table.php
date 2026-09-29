<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Code-barres (EAN/UPC) et marque du produit, lus au scan de l'étiquette.
 *
 * Le code-barres est indexé : un produit déjà publié sur ASSO sert de
 * première source quand un autre vendeur scanne le même article.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('barcode', 32)->nullable()->after('slug')->index();
            $table->string('brand', 120)->nullable()->after('barcode');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['barcode']);
            $table->dropColumn(['barcode', 'brand']);
        });
    }
};
