<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vidéo de présentation d'un produit (grossistes Chine / Turquie / Dubaï).
 *
 * La vidéo est envoyée AVANT l'enregistrement du produit (upload asynchrone par
 * morceaux depuis l'admin) : `product_id` reste nul jusqu'à la sauvegarde du
 * formulaire. Les vidéos orphelines sont purgées par `product-videos:prune`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_videos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();

            // Chemins relatifs au disque `public`.
            $table->string('original_path')->nullable();   // fichier reçu, supprimé après conversion
            $table->string('path')->nullable();            // version lue sur la fiche (720p, son)
            $table->string('preview_path')->nullable();    // boucle muette légère des cartes
            $table->string('poster_path')->nullable();     // image affichée avant lecture

            $table->string('original_name')->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->decimal('duration', 8, 2)->nullable();  // secondes

            // pending → processing → ready | failed
            $table->string('status', 20)->default('pending')->index();
            $table->string('error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_videos');
    }
};
