<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Commandes fournisseur (lot 4) : pour chaque commande ASSO payée qui contient
 * des produits CJ, la commande passée chez CJ (livrée à l'entrepôt de Douala),
 * ses étapes d'envoi et de paiement, et les lignes concernées.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            // awaiting_approval | submitting | needs_review | awaiting_balance | paid | shipped
            // | delivered | cancelled | supplier_cancelled | failed
            $table->string('status', 30)->default('awaiting_approval');
            $table->boolean('is_sandbox')->default(true);
            // Numéro envoyé au fournisseur (unique chez lui) et identifiants renvoyés.
            $table->string('external_number', 50);
            $table->string('external_order_id', 100)->nullable();
            $table->string('shipment_order_id', 100)->nullable();
            $table->string('pay_id', 100)->nullable();
            $table->string('external_status', 30)->nullable();
            $table->string('logistic_name')->nullable();
            $table->string('tracking_number')->nullable();
            $table->string('tracking_url', 500)->nullable();
            // Coût prévu (prix d'achat + transport retenus à l'import) et coût réel.
            $table->decimal('expected_cost_usd', 12, 2)->nullable();
            $table->decimal('quoted_cost_usd', 12, 2)->nullable();
            $table->decimal('paid_cost_usd', 12, 2)->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->text('last_error')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();
            $table->unique(['supplier_id', 'order_id']);
            $table->unique(['supplier_id', 'external_number']);
            $table->index(['supplier_id', 'status']);
            $table->index('external_order_id');
        });

        Schema::create('supplier_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_variant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('external_variant_id', 100);
            $table->unsignedInteger('quantity');
            $table->decimal('unit_cost_usd', 12, 2)->nullable();
            $table->decimal('unit_freight_usd', 12, 2)->nullable();
            $table->timestamps();
            $table->unique(['supplier_order_id', 'order_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_order_items');
        Schema::dropIfExists('supplier_orders');
    }
};
