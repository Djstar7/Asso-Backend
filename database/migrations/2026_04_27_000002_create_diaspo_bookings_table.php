<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('diaspo_bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('diaspo_offer_id')->constrained()->onDelete('cascade');
            $table->foreignId('buyer_user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('seller_user_id')->constrained('users')->onDelete('cascade');

            // Détails de la réservation
            $table->decimal('kg_booked', 8, 2);
            $table->decimal('price_per_kg', 10, 2);
            $table->decimal('subtotal', 10, 2);
            $table->decimal('commission_amount', 10, 2)->default(0);
            $table->decimal('total_price', 10, 2);

            // Status
            $table->enum('status', ['pending', 'paid', 'confirmed', 'cancelled', 'completed'])->default('pending');

            // Confirmation par code (comme les orders)
            $table->string('confirmation_code', 6);
            $table->timestamp('confirmed_by_buyer_at')->nullable();

            // Paiement
            $table->enum('payment_status', ['pending', 'completed', 'refunded'])->default('pending');
            $table->string('payment_reference')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('refunded_at')->nullable();

            // Communication
            $table->foreignId('conversation_id')->nullable()->constrained()->onDelete('set null');
            $table->text('notes')->nullable();

            // Raison d'annulation si applicable
            $table->text('cancel_reason')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Index
            $table->index(['buyer_user_id', 'status']);
            $table->index(['seller_user_id', 'status']);
            $table->index(['diaspo_offer_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('diaspo_bookings');
    }
};
