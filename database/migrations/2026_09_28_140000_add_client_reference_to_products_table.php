<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Référence fournie par l'application pour un produit saisi hors ligne.
 *
 * L'application renvoie un produit tant qu'elle n'a pas reçu de réponse : si
 * la réponse s'est perdue alors que le produit était créé, le renvoi portant
 * la même référence rend le produit existant au lieu d'en créer un second.
 * Unique par vendeur ; null pour les créations ordinaires (plusieurs null
 * autorisés).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('client_reference', 64)->nullable()->after('slug');
            $table->unique(['user_id', 'client_reference']);
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'client_reference']);
            $table->dropColumn('client_reference');
        });
    }
};
