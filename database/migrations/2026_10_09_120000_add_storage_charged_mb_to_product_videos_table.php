<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Espace décompté du forfait vendeur pour une vidéo produit.
 *
 * Mémorisé à part de `size_bytes`, que la conversion remplace par la taille
 * du fichier final : on rend au forfait exactement ce qui en a été retiré.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_videos', function (Blueprint $table) {
            $table->decimal('storage_charged_mb', 12, 4)->default(0)->after('size_bytes');
        });
    }

    public function down(): void
    {
        Schema::table('product_videos', function (Blueprint $table) {
            $table->dropColumn('storage_charged_mb');
        });
    }
};
