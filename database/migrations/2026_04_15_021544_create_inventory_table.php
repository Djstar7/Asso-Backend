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
        Schema::create('inventory', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade'); // Le vendeur
            $table->enum('type', ['entry', 'exit']); // Entrée ou sortie
            $table->integer('quantity'); // Quantité (positif pour entrée, négatif pour sortie)
            $table->integer('stock_after')->nullable(); // Stock après l'opération
            $table->foreignId('order_id')->nullable()->constrained()->onDelete('set null'); // Si lié à une commande
            $table->text('notes')->nullable(); // Notes optionnelles
            $table->timestamps();

            // Index pour performances
            $table->index(['product_id', 'created_at']);
            $table->index(['user_id', 'type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory');
    }
};
