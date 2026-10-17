<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Forfaits de stockage « taille illimitée » (recette du 16/10/2026, E6).
 * L'espace utilisé reste compté ; seul le plafond disparaît.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->boolean('is_unlimited_storage')->default(false)->after('storage_size_mb');
        });
        Schema::table('vendor_packages', function (Blueprint $table) {
            $table->boolean('is_unlimited_storage')->default(false)->after('storage_remaining_mb');
        });
    }

    public function down(): void
    {
        Schema::table('packages', fn (Blueprint $table) => $table->dropColumn('is_unlimited_storage'));
        Schema::table('vendor_packages', fn (Blueprint $table) => $table->dropColumn('is_unlimited_storage'));
    }
};
