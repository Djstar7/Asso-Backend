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
        Schema::table('messages', function (Blueprint $table) {
            // Ajouter le champ is_system pour identifier les messages automatiques
            $table->boolean('is_system')->default(false)->after('message');

            // Rendre sender_id nullable pour permettre les messages système
            $table->foreignId('sender_id')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            // Supprimer le champ is_system
            $table->dropColumn('is_system');

            // Remettre sender_id comme non nullable
            $table->foreignId('sender_id')->nullable(false)->change();
        });
    }
};
