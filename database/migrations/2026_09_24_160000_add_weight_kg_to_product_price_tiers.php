<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Poids d'une unité commandée à un palier de gros (un pack de 12, un bidon de 20 L,
 * une pièce) : la quantité ne compte pas la même chose d'un palier à l'autre, un
 * seul poids par produit ne suffit pas pour chiffrer l'expédition au kg et SOLEX.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_price_tiers', function (Blueprint $table) {
            $table->decimal('weight_kg', 10, 3)->nullable()->after('pack_size');
        });
    }

    public function down(): void
    {
        Schema::table('product_price_tiers', function (Blueprint $table) {
            $table->dropColumn('weight_kg');
        });
    }
};
