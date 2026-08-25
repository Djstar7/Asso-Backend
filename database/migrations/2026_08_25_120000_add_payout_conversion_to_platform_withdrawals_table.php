<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Distingue la devise DÉBITÉE au vendeur de la devise VERSÉE sur son compte.
 *
 * Les vendeurs encaissent en XAF (KPay) mais leur IBAN est libellé en EUR : le
 * retrait convertit donc le montant. Sans ces colonnes, un seul champ `currency`
 * devait porter les deux, et un remboursement (payout.failed) risquait de recréditer
 * le vendeur dans la mauvaise devise.
 *
 *   amount_requested + currency        → ce que le vendeur voit et ce qui est débité
 *   payout_amount + payout_currency    → ce qui part réellement vers l'IBAN
 *   exchange_rate                      → taux appliqué, conservé pour l'audit
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_withdrawals', function (Blueprint $table) {
            $table->decimal('payout_amount', 15, 2)->nullable()->after('stripe_response');
            $table->string('payout_currency', 3)->nullable()->after('payout_amount');
            $table->decimal('exchange_rate', 20, 10)->nullable()->after('payout_currency');
        });
    }

    public function down(): void
    {
        Schema::table('platform_withdrawals', function (Blueprint $table) {
            $table->dropColumn(['payout_amount', 'payout_currency', 'exchange_rate']);
        });
    }
};
