<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fournisseurs de dropshipping (CJdropshipping, puis Printful) : une ligne par
 * fournisseur. Les secrets (clé API, jetons) sont chiffrés par le modèle.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name');
            $table->boolean('is_active')->default(false);
            // Bac à sable : les commandes sont créées avec isSandbox=1 (paiement simulé).
            $table->boolean('is_sandbox')->default(true);
            $table->text('api_key')->nullable();
            $table->text('access_token')->nullable();
            $table->timestamp('access_token_expires_at')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('refresh_token_expires_at')->nullable();
            // Identifiant du compte chez le fournisseur (CJ : openId, clé des signatures wallet).
            $table->string('account_id')->nullable();
            // Jeton secret placé dans l'URL des webhooks (les webhooks CJ ne sont pas signés).
            $table->string('webhook_token', 64)->nullable()->unique();
            // Réglages : marge, marge de change, seuil d'alerte du solde, pays de départ…
            $table->json('settings')->nullable();
            $table->decimal('balance_usd', 12, 2)->nullable();
            $table->decimal('balance_frozen_usd', 12, 2)->nullable();
            $table->timestamp('balance_checked_at')->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('last_error_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suppliers');
    }
};
