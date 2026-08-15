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
        // Bascule prod : si une base déjà déployée porte l'ancien schéma Diaspo (migration
        // 14/04), préserver ses données en renommant l'ancienne table avant de créer le
        // schéma unifié (upstream). Sur base fraîche (test/nouveau déploiement), no-op.
        // Les données legacy restent consultables dans diaspo_offers_legacy_preunify.
        if (Schema::hasTable('diaspo_offers')) {
            Schema::rename('diaspo_offers', 'diaspo_offers_legacy_preunify');
        }

        Schema::create('diaspo_offers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');

            // Status de l'offre
            $table->enum('status', ['pending', 'approved', 'rejected', 'expired', 'completed'])->default('pending');

            // Documents de vérification (optionnel car vérification se fait sur le user)
            $table->enum('verification_status', ['pending', 'verified', 'rejected'])->default('pending');
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->onDelete('set null');
            $table->text('rejection_reason')->nullable();

            // Informations de voyage
            $table->string('departure_country');
            $table->string('departure_city');
            $table->dateTime('departure_datetime');
            $table->string('arrival_country');
            $table->string('arrival_city');
            $table->dateTime('arrival_datetime');

            // Informations commerciales
            $table->decimal('price_per_kg', 10, 2);
            $table->decimal('available_kg', 8, 2);
            $table->decimal('remaining_kg', 8, 2);
            $table->string('currency', 3)->default('EUR');

            // Métadonnées
            $table->integer('views_count')->default(0);
            $table->integer('bookings_count')->default(0);

            $table->timestamps();
            $table->softDeletes();

            // Index pour les recherches
            $table->index(['status', 'verification_status']);
            $table->index(['departure_country', 'arrival_country']);
            $table->index(['departure_datetime', 'arrival_datetime']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('diaspo_offers');
    }
};
