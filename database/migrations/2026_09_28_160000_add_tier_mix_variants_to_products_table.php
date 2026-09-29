<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Paliers de prix en gros : les options (couleurs, tailles) se cumulent-elles
 * pour atteindre un palier ? Oui par défaut (300 rouges + 200 noires = 500) ;
 * sinon chaque option atteint son palier seule, avec son propre prix.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('tier_mix_variants')->default(true)->after('is_wholesale');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('tier_mix_variants');
        });
    }
};
