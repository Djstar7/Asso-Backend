<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_city_grids', function (Blueprint $table) {
            $table->string('coverage_scope', 20)->default('city');
            $table->dropUnique(['deliverer_company_id', 'city']);
            $table->unique(['deliverer_company_id', 'city', 'coverage_scope'], 'delivery_grid_company_city_scope_unique');
        });
    }

    public function down(): void
    {
        if (DB::table('delivery_city_grids')->select('deliverer_company_id', 'city')
            ->groupBy('deliverer_company_id', 'city')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new LogicException('Les grilles territoriales et historiques coexistent : rapprochez-les avant de restaurer l’ancienne contrainte.');
        }
        Schema::table('delivery_city_grids', function (Blueprint $table) {
            $table->dropUnique('delivery_grid_company_city_scope_unique');
            $table->dropColumn('coverage_scope');
            $table->unique(['deliverer_company_id', 'city']);
        });
    }
};
