<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Diaspo (partage de kg entre voyageurs) : offres de trajet, réservations avec
 * paiement KPay direct + séquestre (escrow) plateforme, et vérification KYC.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Vérification d'identité du voyageur (KYC)
        Schema::create('diaspo_verifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->enum('status', ['unverified', 'pending', 'verified', 'rejected'])->default('pending');
            $table->string('document_type')->nullable(); // cni | passport
            $table->string('document_front')->nullable();
            $table->string('document_back')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique('user_id');
        });

        // NB : les tables `diaspo_offers` et `diaspo_bookings` sont créées par les
        // migrations 2026_04_27_000001/000002 (version upstream canonique, schéma retenu
        // lors de la fusion integration/unify-dev). On ne les recrée donc PAS ici pour
        // éviter la collision « table already exists ». Seul diaspo_verifications reste ici.
    }

    public function down(): void
    {
        Schema::dropIfExists('diaspo_verifications');
    }
};
