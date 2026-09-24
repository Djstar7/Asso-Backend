<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ville et pays du nouvel emplacement demandé, tels que l'app les a lus sur la
 * carte : repris tels quels à la validation plutôt que déduits de l'adresse.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_location_requests', function (Blueprint $table) {
            $table->string('city', 120)->nullable()->after('address');
            $table->string('country', 120)->nullable()->after('city');
        });
    }

    public function down(): void
    {
        Schema::table('shop_location_requests', function (Blueprint $table) {
            $table->dropColumn(['city', 'country']);
        });
    }
};
