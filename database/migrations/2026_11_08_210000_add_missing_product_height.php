<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('products', 'height_cm')) {
            Schema::table('products', function (Blueprint $table) {
                $table->decimal('height_cm', 8, 2)->nullable();
            });
        }
    }

    public function down(): void
    {
        // La colonne peut contenir des données ou provenir d'une autre migration.
    }
};
