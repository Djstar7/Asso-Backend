<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vérification renforcée du voyageur avion : pièces exigées selon sa
 * nationalité et son pays de résidence, selfie pris en direct, et
 * justificatif de voyage (billet, réservation) joint à chaque offre.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->char('nationality', 2)->nullable()->after('diaspo_rejection_reason');
            $table->char('residence_country', 2)->nullable()->after('nationality');
        });

        Schema::create('diaspo_identity_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // cni|passport|residence_permit|visa|selfie|travel_proof
            $table->string('type', 30);
            $table->string('side', 10)->nullable(); // front|back
            $table->foreignId('document_id')->constrained('documents')->cascadeOnDelete();
            $table->foreignId('diaspo_offer_id')->nullable()->constrained('diaspo_offers')->cascadeOnDelete();
            $table->string('status', 20)->default('pending'); // pending|approved|rejected
            $table->timestamps();

            $table->index(['user_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('diaspo_identity_documents');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['nationality', 'residence_country']);
        });
    }
};
