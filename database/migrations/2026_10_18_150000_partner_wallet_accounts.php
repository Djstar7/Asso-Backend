<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Paiement des partenaires (livraison et maritime) sur le même principe que le
 * retrait ASSO : chaque partenaire a un compte interne géré par ASSO, sans
 * connexion à l'app, dont le Wallet reçoit ses courses et son fret. L'admin
 * retire ce solde pour lui (Mobile Money par pays, ou IBAN Stripe) depuis sa
 * fiche. Remplace le compte « dû » (partner_ledger_entries) et l'IBAN stocké
 * sur le partenaire, jamais mis en production.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deliverer_companies', function (Blueprint $table) {
            $table->foreignId('account_user_id')->nullable()->after('user_id')->constrained('users')->nullOnDelete();
        });

        Schema::dropIfExists('partner_ledger_entries');

        $stripeColumns = array_values(array_filter(
            ['stripe_account_id', 'stripe_external_last4', 'stripe_bank_country', 'stripe_account_holder_name', 'stripe_submitted_at'],
            fn ($column) => Schema::hasColumn('deliverer_companies', $column)
        ));
        if ($stripeColumns) {
            Schema::table('deliverer_companies', fn (Blueprint $table) => $table->dropColumn($stripeColumns));
        }
    }

    public function down(): void
    {
        Schema::table('deliverer_companies', function (Blueprint $table) {
            $table->dropConstrainedForeignId('account_user_id');
        });
    }
};
