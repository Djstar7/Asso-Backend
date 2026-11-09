<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Import : volume d'un carton en CBM, tel que le fournisseur l'indique sur son devis.
 * Le nombre de pièces par carton est le `pack_size` du palier.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['products', 'product_price_tiers'] as $name) {
            if (! Schema::hasColumn($name, 'cbm')) {
                Schema::table($name, function (Blueprint $table) {
                    $table->decimal('cbm', 10, 4)->nullable();
                });
            }
        }
    }

    public function down(): void
    {
        foreach (['products', 'product_price_tiers'] as $name) {
            if (Schema::hasColumn($name, 'cbm')) {
                Schema::table($name, function (Blueprint $table) {
                    $table->dropColumn('cbm');
                });
            }
        }
    }
};
