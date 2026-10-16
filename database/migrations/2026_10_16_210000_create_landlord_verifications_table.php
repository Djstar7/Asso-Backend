<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vérification d'un bailleur : CNI recto/verso, preuve de propriété et vidéo
 * où il tient ses documents. Les pièces restent privées (disque local).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('landlord_verifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('id_document_type', 20)->default('cni');
            $table->string('id_front_path');
            $table->string('id_back_path')->nullable();
            $table->string('ownership_proof_type', 30)->comment('land_title | sale_deed | certificate | lease_contract | other');
            $table->string('ownership_proof_path');
            $table->string('video_path');
            $table->string('status', 20)->default('pending')->index();
            $table->string('rejection_reason')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('landlord_verifications');
    }
};
