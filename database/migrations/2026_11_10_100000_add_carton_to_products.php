<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Produit en gros saisi comme une ligne du devis fournisseur : pièces par carton et
 * poids du carton (le CBM du carton est déjà dans `cbm`). Le poids d'une pièce
 * (`weight`) s'en déduit à l'enregistrement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (! Schema::hasColumn('products', 'pieces_per_carton')) {
                $table->unsignedInteger('pieces_per_carton')->nullable();
            }
            if (! Schema::hasColumn('products', 'carton_weight_kg')) {
                $table->decimal('carton_weight_kg', 10, 3)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['pieces_per_carton', 'carton_weight_kg']);
        });
    }
};
