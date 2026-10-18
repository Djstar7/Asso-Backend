<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ce qu'ASSO doit à chaque partenaire (livraison et maritime). Le prix des
 * courses et du fret reste chez ASSO : chaque course réglée ajoute une ligne
 * « dû », chaque versement une ligne « versement » : virement Stripe vers
 * l'IBAN du partenaire lancé depuis l'admin, ou paiement fait hors application
 * et saisi par l'admin. Solde dû = Σ dus − Σ versements non échoués, en XAF.
 */
return new class extends Migration
{
    public function up(): void
    {
        // IBAN du partenaire synchronisé chez Stripe (compte Connect), comme
        // celui des utilisateurs, mais saisi par l'admin sur la fiche partenaire.
        Schema::table('deliverer_companies', function (Blueprint $table) {
            $table->string('stripe_account_id')->nullable();
            $table->string('stripe_external_last4', 4)->nullable();
            $table->char('stripe_bank_country', 2)->nullable();
            $table->string('stripe_account_holder_name')->nullable();
            $table->timestamp('stripe_submitted_at')->nullable();
        });

        Schema::create('partner_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deliverer_company_id')->constrained('deliverer_companies')->cascadeOnDelete();
            $table->string('type', 10); // earning|payout
            $table->decimal('amount', 14, 2); // XAF, toujours positif
            // Origine d'un dû : order|deposit_order|dispute_shipment|maritime_booking
            $table->string('source_type', 30)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('description');
            // Versement : moyen, référence, date du paiement, admin qui l'a saisi.
            $table->string('payment_method', 30)->nullable();
            $table->string('reference')->nullable();
            $table->date('paid_on')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            // Virement IBAN (Stripe Connect) : processing → completed | failed.
            $table->string('status', 20)->default('completed');
            $table->decimal('payout_amount', 14, 2)->nullable();
            $table->char('payout_currency', 3)->nullable();
            $table->string('stripe_transfer_id')->nullable()->index();
            $table->string('stripe_payout_id')->nullable()->index();
            $table->string('failure_message')->nullable();
            $table->timestamps();

            $table->index(['deliverer_company_id', 'type']);
            // Une même course ne crée qu'un dû.
            $table->unique(['type', 'source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_ledger_entries');
        Schema::table('deliverer_companies', function (Blueprint $table) {
            $table->dropColumn(['stripe_account_id', 'stripe_external_last4', 'stripe_bank_country', 'stripe_account_holder_name', 'stripe_submitted_at']);
        });
    }
};
