<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Suppression des colonnes de solde/lock PayPal résiduelles sur `users`.
 *
 * PayPal a été retiré partout (rails, routes, services, admin). Le solde wallet
 * est désormais exclusivement KPay (source de vérité : table `wallet_balances`).
 * Ces colonnes n'étaient plus alimentées et sont supprimées ici.
 *
 * Chaque drop est protégé par `Schema::hasColumn` pour l'idempotence
 * (rejouable sans erreur si une colonne a déjà disparu).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'paypal_wallet_balance')) {
                $table->dropColumn('paypal_wallet_balance');
            }
            if (Schema::hasColumn('users', 'locked_paypal_balance')) {
                $table->dropColumn('locked_paypal_balance');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'paypal_wallet_balance')) {
                $table->decimal('paypal_wallet_balance', 15, 2)->default(0);
            }
            if (!Schema::hasColumn('users', 'locked_paypal_balance')) {
                $table->decimal('locked_paypal_balance', 15, 2)->default(0);
            }
        });
    }
};
