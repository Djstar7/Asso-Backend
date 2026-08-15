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
        Schema::table('users', function (Blueprint $table) {
            // Vérification DIASPO
            $table->enum('diaspo_verification_status', ['unverified', 'pending', 'verified', 'rejected'])
                ->default('unverified')
                ->after('fcm_token');

            // Document de vérification (CNI ou Passeport)
            $table->foreignId('diaspo_id_document_id')
                ->nullable()
                ->after('diaspo_verification_status')
                ->constrained('documents')
                ->onDelete('set null');

            // Date de vérification
            $table->timestamp('diaspo_verified_at')
                ->nullable()
                ->after('diaspo_id_document_id');

            // Raison de rejet si applicable
            $table->text('diaspo_rejection_reason')
                ->nullable()
                ->after('diaspo_verified_at');

            // Index pour recherches rapides
            $table->index('diaspo_verification_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['diaspo_id_document_id']);
            $table->dropIndex(['diaspo_verification_status']);
            $table->dropColumn([
                'diaspo_verification_status',
                'diaspo_id_document_id',
                'diaspo_verified_at',
                'diaspo_rejection_reason'
            ]);
        });
    }
};
