<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Frais réellement prélevés par le prestataire Mobile Money sur chaque opération
 * aboutie (KPay : `feeAmount` de l'objet Payment / Withdrawal). Une ligne par
 * opération prestataire ; alimentée à chaque lecture du statut et rattrapée par
 * `kpay:sync-fees`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_gateway_fees', function (Blueprint $table) {
            $table->id();
            $table->string('gateway', 20);              // kpay
            $table->string('direction', 10);            // payin | payout
            $table->string('gateway_id', 100);          // pay_xxx | wdr_xxx
            $table->string('external_id')->nullable();  // externalId transmis à l'initialisation
            $table->decimal('amount', 14, 2);
            $table->decimal('net_amount', 14, 2)->nullable();
            $table->decimal('fee_amount', 14, 2)->default(0);
            $table->string('currency', 3)->default('XAF');
            $table->decimal('fee_amount_xaf', 14, 2)->nullable();
            $table->string('provider', 40)->nullable(); // MTN_MOMO_CMR, ORANGE_CMR…
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['gateway', 'gateway_id']);
            $table->index(['direction', 'completed_at']);
            $table->index('external_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_gateway_fees');
    }
};
