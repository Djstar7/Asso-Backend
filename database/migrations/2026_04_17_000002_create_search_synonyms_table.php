<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('search_synonyms', function (Blueprint $table) {
            $table->id();
            $table->string('term'); // Le terme recherché (ex: "PC")
            $table->string('synonym'); // Le synonyme (ex: "ordinateur")
            $table->integer('weight')->default(1); // Poids pour le ranking (plus élevé = plus important)
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // Index pour recherche rapide
            $table->index('term');
            $table->index(['term', 'is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('search_synonyms');
    }
};
