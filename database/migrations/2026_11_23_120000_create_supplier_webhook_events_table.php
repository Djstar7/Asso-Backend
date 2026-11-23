<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Synchronisation des fournisseurs (lot 3) : journal des alertes reçues
 * (dédoublonnées par identifiant de message) et suivi de l'inscription des
 * produits aux alertes du fournisseur.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $table->string('message_id', 200);
            // PRODUCT, VARIANT, STOCK, ORDER, LOGISTIC…
            $table->string('type', 30);
            $table->string('message_type', 30)->nullable();
            // Signature vérifiée : sinon l'alerte ne sert qu'à relire la fiche chez le fournisseur.
            $table->boolean('signature_verified')->default(false);
            $table->json('payload');
            // pending | processed | ignored | failed
            $table->string('status', 20)->default('pending');
            $table->string('note', 500)->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->unique(['supplier_id', 'message_id']);
            $table->index(['supplier_id', 'created_at']);
        });

        Schema::table('supplier_products', function (Blueprint $table) {
            $table->timestamp('subscribed_at')->nullable()->after('last_synced_at');
            $table->timestamp('subscribe_failed_at')->nullable()->after('subscribed_at');
        });
    }

    public function down(): void
    {
        Schema::table('supplier_products', function (Blueprint $table) {
            $table->dropColumn(['subscribed_at', 'subscribe_failed_at']);
        });
        Schema::dropIfExists('supplier_webhook_events');
    }
};
