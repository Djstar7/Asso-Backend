<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Virement IBAN (Stripe Connect) fait par ASSO depuis le back-office à l'issue
 * d'un litige sur une commande payée par carte : un par bénéficiaire, au plus.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('disputes', function (Blueprint $table) {
            $table->foreignId('client_bank_withdrawal_id')->nullable()->after('refunded_at')
                ->constrained('platform_withdrawals')->nullOnDelete();
            $table->foreignId('vendor_bank_withdrawal_id')->nullable()->after('client_bank_withdrawal_id')
                ->constrained('platform_withdrawals')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('disputes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('vendor_bank_withdrawal_id');
            $table->dropConstrainedForeignId('client_bank_withdrawal_id');
        });
    }
};
