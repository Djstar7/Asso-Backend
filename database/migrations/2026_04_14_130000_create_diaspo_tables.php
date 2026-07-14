<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Diaspo (partage de kg entre voyageurs) : offres de trajet, réservations avec
 * paiement KPay direct + séquestre (escrow) plateforme, et vérification KYC.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Vérification d'identité du voyageur (KYC)
        Schema::create('diaspo_verifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->enum('status', ['unverified', 'pending', 'verified', 'rejected'])->default('pending');
            $table->string('document_type')->nullable(); // cni | passport
            $table->string('document_front')->nullable();
            $table->string('document_back')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique('user_id');
        });

        // Offres de transport (un voyageur propose des kg sur un trajet)
        Schema::create('diaspo_offers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade'); // voyageur (vendeur)
            $table->enum('status', ['active', 'full', 'closed', 'cancelled'])->default('active');
            $table->enum('verification_status', ['unverified', 'pending', 'verified', 'rejected'])->default('pending');
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('rejection_reason')->nullable();

            $table->string('departure_country');
            $table->string('departure_city');
            $table->timestamp('departure_datetime');
            $table->string('arrival_country');
            $table->string('arrival_city');
            $table->timestamp('arrival_datetime');

            $table->decimal('price_per_kg', 15, 2);
            $table->decimal('available_kg', 10, 2);
            $table->decimal('remaining_kg', 10, 2);
            $table->string('currency', 3)->default('XAF');

            $table->unsignedInteger('views_count')->default(0);
            $table->unsignedInteger('bookings_count')->default(0);
            $table->timestamps();
        });

        // Réservations (un acheteur réserve des kg sur une offre)
        Schema::create('diaspo_bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('diaspo_offer_id')->constrained()->onDelete('cascade');
            $table->foreignId('buyer_user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('seller_user_id')->constrained('users')->onDelete('cascade');

            $table->decimal('kg_booked', 10, 2);
            $table->decimal('price_per_kg', 15, 2);
            $table->decimal('subtotal', 15, 2);
            $table->decimal('commission_amount', 15, 2)->default(0);
            $table->decimal('total_price', 15, 2);
            $table->string('currency', 3)->default('XAF');

            $table->enum('status', ['pending', 'confirmed', 'in_transit', 'completed', 'cancelled'])->default('pending');
            $table->string('confirmation_code', 6);
            $table->timestamp('confirmed_by_buyer_at')->nullable();

            // Paiement KPay direct + séquestre plateforme
            $table->enum('payment_status', ['pending', 'paid', 'failed', 'refunded'])->default('pending');
            $table->string('payment_reference')->nullable(); // id KPay (pay_xxx)
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('refunded_at')->nullable();

            $table->foreignId('conversation_id')->nullable();
            $table->text('notes')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('diaspo_bookings');
        Schema::dropIfExists('diaspo_offers');
        Schema::dropIfExists('diaspo_verifications');
    }
};
