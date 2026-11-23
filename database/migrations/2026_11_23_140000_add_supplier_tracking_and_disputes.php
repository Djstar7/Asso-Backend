<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Suivi des commandes fournisseur (lot 5) : arrivée prévue et réelle à
 * l'entrepôt de Douala, dernier état du transporteur, et litiges ouverts
 * chez le fournisseur.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_orders', function (Blueprint $table) {
            $table->timestamp('expected_hub_at')->nullable()->after('delivered_at');
            $table->timestamp('hub_received_at')->nullable()->after('expected_hub_at');
            $table->foreignId('hub_received_by')->nullable()->after('hub_received_at')->constrained('users')->nullOnDelete();
            // Code de suivi CJ (0 à 14) et dernier événement du transporteur.
            $table->unsignedSmallInteger('logistics_status')->nullable()->after('tracking_url');
            $table->string('logistics_event', 500)->nullable()->after('logistics_status');
            $table->index(['supplier_id', 'expected_hub_at']);
        });

        Schema::create('supplier_disputes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_order_id')->constrained()->cascadeOnDelete();
            $table->string('external_dispute_id', 100)->nullable();
            $table->string('business_id', 100)->unique();
            $table->unsignedInteger('reason_id');
            $table->string('reason_name')->nullable();
            // 1 = remboursement, 2 = renvoi du produit
            $table->unsignedTinyInteger('expect_type');
            $table->text('message');
            $table->json('lines');
            $table->decimal('amount_usd', 12, 2)->nullable();
            $table->string('status', 50)->nullable();
            // 1 = remboursé, 2 = renvoyé, 3 = refusé (décision finale du fournisseur)
            $table->unsignedTinyInteger('final_result')->nullable();
            $table->decimal('refund_usd', 12, 2)->nullable();
            $table->string('resend_order_id', 100)->nullable();
            $table->foreignId('opened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->index('external_dispute_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_disputes');
        Schema::table('supplier_orders', function (Blueprint $table) {
            $table->dropIndex(['supplier_id', 'expected_hub_at']);
            $table->dropConstrainedForeignId('hub_received_by');
            $table->dropColumn(['expected_hub_at', 'hub_received_at', 'logistics_status', 'logistics_event']);
        });
    }
};
