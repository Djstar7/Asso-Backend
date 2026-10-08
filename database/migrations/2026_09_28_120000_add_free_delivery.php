<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Livraison gratuite offerte par le vendeur, sur toute sa boutique ou produit par
 * produit. Le produit prime : null = suit la boutique, true/false = choix explicite
 * (un produit peut être exclu d'une boutique en livraison gratuite).
 *
 * Sur la commande : l'acheteur ne paie pas la course ; son prix affiché
 * (free_delivery_amount = part transporteur + commission ASSO) est retenu sur la
 * part du vendeur.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->boolean('free_delivery')->default(false)->after('is_certified');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->boolean('free_delivery')->nullable()->after('is_wholesale');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->boolean('free_delivery')->default(false)->after('delivery_commission');
            $table->decimal('free_delivery_amount', 12, 2)->default(0)->after('free_delivery');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['free_delivery', 'free_delivery_amount']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('free_delivery');
        });

        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn('free_delivery');
        });
    }
};
