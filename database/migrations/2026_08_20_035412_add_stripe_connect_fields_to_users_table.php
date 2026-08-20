<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stripe Connect (Custom Account + IBAN + validation admin).
 *
 * Le vendeur soumet ses infos + IBAN → un compte Stripe Custom est créé et son
 * compte externe (IBAN) attaché. La plateforme (ASSO) valide ou rejette ensuite
 * MANUELLEMENT via l'admin (gate interne, distinct de la vérification Stripe) :
 * seul un compte 'approved' pourra recevoir des payouts IBAN.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Identifiant du compte Stripe Connect (acct_...)
            $table->string('stripe_account_id')->nullable()->after('locked_paypal_balance');

            // Statut de validation ASSO : null (jamais soumis) | pending | approved | rejected
            $table->string('stripe_account_status')->nullable()->after('stripe_account_id');
            $table->text('stripe_rejection_reason')->nullable()->after('stripe_account_status');
            $table->timestamp('stripe_submitted_at')->nullable()->after('stripe_rejection_reason');
            $table->timestamp('stripe_verified_at')->nullable()->after('stripe_submitted_at');

            // Traçabilité du compte externe (IBAN) — jamais l'IBAN en clair.
            $table->string('stripe_external_last4', 4)->nullable()->after('stripe_verified_at');
            $table->string('stripe_bank_country', 2)->nullable()->after('stripe_external_last4');
            $table->string('stripe_account_holder_name')->nullable()->after('stripe_bank_country');

            $table->index('stripe_account_id');
            $table->index('stripe_account_status');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['stripe_account_id']);
            $table->dropIndex(['stripe_account_status']);
            $table->dropColumn([
                'stripe_account_id',
                'stripe_account_status',
                'stripe_rejection_reason',
                'stripe_submitted_at',
                'stripe_verified_at',
                'stripe_external_last4',
                'stripe_bank_country',
                'stripe_account_holder_name',
            ]);
        });
    }
};
