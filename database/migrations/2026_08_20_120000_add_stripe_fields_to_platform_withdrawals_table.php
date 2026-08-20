<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ajoute les colonnes de suivi d'un payout Stripe Connect (virement IBAN vendeur)
 * aux retraits plateforme, en miroir des colonnes KPay / PayPal existantes.
 *
 * Voir StripeConnectController (soumission IBAN) et WalletController::initiateStripeWithdrawal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_withdrawals', function (Blueprint $table) {
            // Transfer plateforme → compte Connect du vendeur (mouvement autoritatif).
            $table->string('stripe_transfer_id')->nullable()->after('paypal_response');
            // Payout compte Connect → IBAN du vendeur (peut rester null si payout automatique).
            $table->string('stripe_payout_id')->nullable()->after('stripe_transfer_id');
            // Réponse brute Stripe (transfer + payout) pour audit.
            $table->json('stripe_response')->nullable()->after('stripe_payout_id');
        });
    }

    public function down(): void
    {
        Schema::table('platform_withdrawals', function (Blueprint $table) {
            $table->dropColumn(['stripe_transfer_id', 'stripe_payout_id', 'stripe_response']);
        });
    }
};
