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
        // Ajouter diaspo_offer_id à la table conversations
        Schema::table('conversations', function (Blueprint $table) {
            $table->foreignId('diaspo_offer_id')
                ->nullable()
                ->after('product_id')
                ->constrained('diaspo_offers')
                ->onDelete('set null')
                ->comment('Offre Diaspo concernée');
        });

        // Ajouter diaspo_offer_id à la table messages
        Schema::table('messages', function (Blueprint $table) {
            $table->foreignId('diaspo_offer_id')
                ->nullable()
                ->after('message')
                ->constrained('diaspo_offers')
                ->onDelete('set null')
                ->comment('Offre Diaspo taguée dans le message');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropForeign(['diaspo_offer_id']);
            $table->dropColumn('diaspo_offer_id');
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->dropForeign(['diaspo_offer_id']);
            $table->dropColumn('diaspo_offer_id');
        });
    }
};
