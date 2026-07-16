<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trace la devise et le montant réellement débités à l'utilisateur pour un
 * paiement Mobile Money (kpay_direct), qui peut différer du total en XAF
 * (conversion selon l'opérateur choisi).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('payment_currency', 3)->nullable()->after('payment_reference');
            $table->decimal('payment_amount', 14, 2)->nullable()->after('payment_currency');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['payment_currency', 'payment_amount']);
        });
    }
};
