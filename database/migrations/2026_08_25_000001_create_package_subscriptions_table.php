<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Abonnements vendeur payés par RAIL DIRECT (kpay_direct / paypal_direct / stripe_direct).
 *
 * Trace la tentative de paiement d'un abonnement à un package pour permettre la
 * confirmation asynchrone par polling — exactement comme une commande directe.
 * Le VendorPackage réel n'est créé/cumulé QU'À la confirmation du paiement ; tant que
 * le paiement est 'pending', aucun espace n'est crédité au vendeur.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('package_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('package_id')->constrained('packages')->onDelete('cascade');

            // Rail de paiement : kpay_direct | paypal_direct | stripe_direct
            $table->string('payment_method');
            // pending → paid → (VendorPackage créé) | failed
            $table->string('status')->default('pending');

            // Référence chez le PSP (pay_xxx KPay, order id PayPal, session id Stripe)
            $table->string('payment_reference')->nullable();
            // Devise/montant réellement débités par le PSP (après conversion depuis XAF)
            $table->string('payment_currency', 3)->nullable();
            $table->decimal('payment_amount', 12, 2)->nullable();
            // Montant de référence en devise pivot XAF (prix du package au moment de l'achat)
            $table->decimal('amount_xaf', 12, 2)->default(0);

            // URL de checkout à ouvrir en WebView (PayPal / Stripe) — null pour KPay
            $table->string('approval_url', 1024)->nullable();

            // VendorPackage résultant, renseigné à la confirmation du paiement
            $table->foreignId('vendor_package_id')->nullable()
                ->constrained('vendor_packages')->nullOnDelete();

            $table->timestamp('paid_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('user_id');
            $table->index('status');
            $table->index('payment_reference');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('package_subscriptions');
    }
};
