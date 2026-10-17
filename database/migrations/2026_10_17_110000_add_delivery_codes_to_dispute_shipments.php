<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Courses de litige confiées au livreur partenaire, comme une commande (recette du
 * 16/10/2026, E1-E4) : le livreur accepte la course, récupère le colis puis la
 * termine avec le code à 6 chiffres du destinataire — le client pour un produit de
 * remplacement, le vendeur pour un retour (qui rembourse aussitôt le client).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dispute_shipments', function (Blueprint $table) {
            $table->string('confirmation_code', 6)->nullable()->after('status');
            $table->foreignId('delivery_person_id')->nullable()->after('deliverer_company_id')->constrained('users')->nullOnDelete();
            $table->timestamp('accepted_at')->nullable()->after('paid_at');
            $table->timestamp('picked_up_at')->nullable()->after('accepted_at');
        });
    }

    public function down(): void
    {
        Schema::table('dispute_shipments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('delivery_person_id');
            $table->dropColumn(['confirmation_code', 'accepted_at', 'picked_up_at']);
        });
    }
};
