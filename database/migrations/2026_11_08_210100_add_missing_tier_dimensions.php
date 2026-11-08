<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['length_cm', 'width_cm', 'height_cm'] as $column) {
            if (! Schema::hasColumn('product_price_tiers', $column)) {
                Schema::table('product_price_tiers', function (Blueprint $table) use ($column) {
                    $table->decimal($column, 8, 2)->nullable();
                });
            }
        }
    }

    public function down(): void
    {
        // Conserve les données et les colonnes éventuellement créées ailleurs.
    }
};
