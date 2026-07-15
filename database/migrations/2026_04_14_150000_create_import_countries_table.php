<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pays d'origine des "produits importés" (Chine, Turquie, Dubaï…).
 * Gérés en base pour pouvoir en ajouter/retirer sans redéployer l'app mobile.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_countries', function (Blueprint $table) {
            $table->id();
            $table->string('code', 2)->unique();   // ISO 3166-1 alpha-2 : CN, TR, AE…
            $table->string('name');                 // Libellé affiché (ex. "Dubaï")
            $table->string('flag')->nullable();     // Emoji drapeau (ex. 🇨🇳)
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_countries');
    }
};
