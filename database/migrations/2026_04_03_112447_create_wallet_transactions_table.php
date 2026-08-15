<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Table pour l'historique des transactions wallet.
     * Enregistre tous les mouvements: recharges, paiements, remboursements.
     */
    public function up(): void
    {
        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->id();

            // Utilisateur concerné
            $table->foreignId('user_id')
                ->constrained('users')
                ->onDelete('cascade');

            // Type de transaction
            $table->enum('type', [
                'credit',         // Recharge wallet (ajout d'argent)
                'debit',          // Paiement avec wallet (retrait d'argent)
                'refund',         // Remboursement
                'bonus',          // Bonus offert (promo, parrainage)
                'adjustment',     // Ajustement admin
                'lock',           // Blocage escrow (fonds bloqués)
                'unlock',         // Déblocage escrow (remise à disposition)
                'escrow_release', // Libération escrow (débloque + débite)
            ]);

            // Montant (positif pour credit, négatif pour debit)
            $table->decimal('amount', 10, 2);

            // Solde avant et après la transaction
            $table->decimal('balance_before', 10, 2);
            $table->decimal('balance_after', 10, 2);

            // Description de la transaction
            $table->string('description');

            // Référence externe (order_id, subscription_id, etc.)
            $table->string('reference_type')->nullable(); // 'order', 'subscription', 'platform_withdrawal', etc.
            $table->unsignedBigInteger('reference_id')->nullable();

            // Transaction source (pour les recharges)
            $table->foreignId('payment_id')
                ->nullable()
                ->constrained('transactions')
                ->onDelete('set null');

            // Provider (freemopay ou paypal)
            $table->string('provider')->nullable()->default('freemopay');

            // Métadonnées additionnelles (JSON)
            $table->json('metadata')->nullable();

            // Statut de la transaction
            $table->enum('status', ['pending', 'completed', 'failed', 'cancelled'])->default('completed');

            // Admin qui a effectué l'ajustement (si applicable)
            $table->foreignId('admin_id')
                ->nullable()
                ->constrained('users')
                ->onDelete('set null');

            $table->timestamps();
            $table->softDeletes();

            // Index pour performances
            $table->index('user_id');
            $table->index('type');
            $table->index('status');
            $table->index('provider');
            $table->index(['user_id', 'created_at']);
            $table->index(['reference_type', 'reference_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('wallet_transactions');
    }
};
