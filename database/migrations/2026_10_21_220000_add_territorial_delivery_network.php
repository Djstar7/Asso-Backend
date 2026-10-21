<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_territories', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->unsignedTinyInteger('level');
            $table->string('parent_code', 30)->nullable()->index();
            $table->string('name');
            $table->string('name_en');
            $table->decimal('latitude', 11, 8);
            $table->decimal('longitude', 11, 8);
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
        Schema::table('deliverer_companies', fn (Blueprint $t) => $t->boolean('territorial_network_enabled')->default(false));
        Schema::table('delivery_city_grids', function (Blueprint $t) {
            $t->string('department_code', 30)->nullable()->index();
            $t->unique(['deliverer_company_id', 'department_code']);
        });
        Schema::table('delivery_routes', function (Blueprint $t) {
            $t->string('network_key', 80)->nullable()->unique();
            $t->string('network_level', 20)->nullable();
            $t->string('origin_territory_code', 30)->nullable();
            $t->string('destination_territory_code', 30)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('delivery_routes', function (Blueprint $t) {
            $t->dropUnique(['network_key']);
            $t->dropColumn(['network_key', 'network_level', 'origin_territory_code', 'destination_territory_code']);
        });
        Schema::table('delivery_city_grids', function (Blueprint $t) {
            $t->dropUnique(['deliverer_company_id', 'department_code']);
            $t->dropColumn('department_code');
        });
        Schema::table('deliverer_companies', fn (Blueprint $t) => $t->dropColumn('territorial_network_enabled'));
        Schema::dropIfExists('delivery_territories');
    }
};
