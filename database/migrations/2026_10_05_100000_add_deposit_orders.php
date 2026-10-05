<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Commande avec acompte (produits sur commande / importés).
 *
 * Produit : le vendeur (ou l'admin) active l'option et fixe l'acompte en % du prix
 * acheteur. Commande : l'acheteur paie l'acompte (+ la livraison) à la commande ; le
 * solde n'est payable qu'APRÈS la livraison ou le retrait et la vérification faite
 * ensemble par le client et un employé ASSO. Le vendeur n'est réglé qu'une fois le
 * solde payé.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('deposit_enabled')->default(false)->after('free_delivery');
            $table->decimal('deposit_rate', 5, 2)->nullable()->after('deposit_enabled');
        });

        Schema::table('orders', function (Blueprint $table) {
            // full = paiement unique (parcours classique) ; deposit = acompte + solde.
            $table->string('payment_plan', 10)->default('full')->after('payment_status');
            $table->decimal('deposit_amount', 12, 2)->nullable()->after('payment_plan');
            $table->decimal('balance_amount', 12, 2)->nullable()->after('deposit_amount');
            // locked → unlocked (vérification validée) → paid ; cancelled si clôturée.
            $table->string('balance_status', 12)->nullable()->after('balance_amount');
            $table->string('balance_payment_method', 30)->nullable()->after('balance_status');
            $table->string('balance_payment_reference')->nullable()->after('balance_payment_method');
            $table->string('balance_payment_currency', 3)->nullable()->after('balance_payment_reference');
            $table->decimal('balance_payment_amount', 14, 2)->nullable()->after('balance_payment_currency');
            $table->timestamp('balance_unlocked_at')->nullable()->after('balance_payment_amount');
            $table->timestamp('balance_paid_at')->nullable()->after('balance_unlocked_at');
            // pending → to_contact (produit présenté) → contacted → verified | issue.
            $table->string('verification_status', 12)->nullable()->after('balance_paid_at');
            $table->foreignId('verified_by')->nullable()->after('verification_status')->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable()->after('verified_by');
            $table->text('verification_note')->nullable()->after('verified_at');
            // Clôture au cas par cas : part de l'acompte rendue au client / versée au vendeur.
            $table->decimal('deposit_refund_amount', 12, 2)->nullable()->after('verification_note');
            $table->decimal('deposit_vendor_amount', 12, 2)->nullable()->after('deposit_refund_amount');

            $table->index(['payment_plan', 'verification_status']);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['payment_plan', 'verification_status']);
            $table->dropConstrainedForeignId('verified_by');
            $table->dropColumn([
                'payment_plan', 'deposit_amount', 'balance_amount', 'balance_status',
                'balance_payment_method', 'balance_payment_reference', 'balance_payment_currency',
                'balance_payment_amount', 'balance_unlocked_at', 'balance_paid_at',
                'verification_status', 'verified_at', 'verification_note',
                'deposit_refund_amount', 'deposit_vendor_amount',
            ]);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['deposit_enabled', 'deposit_rate']);
        });
    }
};
