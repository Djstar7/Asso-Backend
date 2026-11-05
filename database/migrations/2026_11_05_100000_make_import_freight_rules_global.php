<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le seuil avion et le diviseur volumétrique valent pour tous les pays : ils passent
 * dans les paramètres (ImportFreightService). Ces colonnes n'ont jamais servi en
 * production, aucune valeur n'est à reprendre.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_countries', function (Blueprint $table) {
            $table->dropColumn(['air_max_weight_kg', 'air_volumetric_divisor']);
        });
    }

    public function down(): void
    {
        Schema::table('import_countries', function (Blueprint $table) {
            $table->decimal('air_max_weight_kg', 10, 2)->default(100);
            $table->unsignedInteger('air_volumetric_divisor')->default(6000);
        });
    }
};
