<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Délai de livraison annoncé au client, en jours ouvrables (1 à 20).
 *
 * Fixé sur le produit (vendeur ou admin) ; à défaut, celui de la catégorie, puis le
 * défaut global réglé dans le Dashboard (cf. App\Support\DeliveryDelay).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedTinyInteger('delivery_days_min')->nullable()->after('free_delivery');
            $table->unsignedTinyInteger('delivery_days_max')->nullable()->after('delivery_days_min');
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->unsignedTinyInteger('delivery_days_min')->nullable();
            $table->unsignedTinyInteger('delivery_days_max')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['delivery_days_min', 'delivery_days_max']);
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn(['delivery_days_min', 'delivery_days_max']);
        });
    }
};
