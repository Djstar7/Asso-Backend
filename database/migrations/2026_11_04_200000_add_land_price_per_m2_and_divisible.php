<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Terrains : prix au m² saisi (sur le terrain, modifiable par lot) et case
 * « morcelable ». Vides sur l'existant : prix conservés, morcelable déduit des lots.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('real_estate_listings', function (Blueprint $table) {
            $table->unsignedBigInteger('price_per_m2')->nullable()->after('old_net_price');
            $table->boolean('is_divisible')->nullable()->after('price_per_m2');
        });

        Schema::table('real_estate_units', function (Blueprint $table) {
            $table->unsignedBigInteger('price_per_m2')->nullable()->after('old_net_price');
        });
    }

    public function down(): void
    {
        Schema::table('real_estate_units', function (Blueprint $table) {
            $table->dropColumn('price_per_m2');
        });

        Schema::table('real_estate_listings', function (Blueprint $table) {
            $table->dropColumn(['price_per_m2', 'is_divisible']);
        });
    }
};
