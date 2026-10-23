<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partner_freight_rates', function (Blueprint $table) {
            $table->unsignedSmallInteger('transit_days_min')->nullable()->change();
            $table->unsignedSmallInteger('transit_days_max')->nullable()->change();
        });
        Schema::table('maritime_bookings', function (Blueprint $table) {
            // Les envois historiques conservent leur règlement à la remise.
            $table->string('partner_freight_payment_timing', 20)->default('completion');
            $table->timestamp('partner_freight_paid_at')->nullable();
            $table->decimal('partner_freight_paid_xaf', 20, 2)->nullable();
        });
        Schema::create('freight_inquiries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('conversation_id')->constrained()->restrictOnDelete();
            $table->char('destination_country', 2);
            $table->decimal('kg', 12, 2);
            $table->decimal('volume_cbm', 12, 4)->nullable();
            $table->string('cargo_category', 20);
            $table->text('description');
            $table->string('status', 20)->default('pending');
            $table->foreignId('maritime_departure_id')->nullable()->constrained()->restrictOnDelete();
            $table->json('departure_snapshot')->nullable();
            $table->decimal('freight_amount', 20, 2)->nullable();
            $table->decimal('partner_amount', 20, 2)->nullable();
            $table->char('currency', 3)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->foreignId('maritime_booking_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('freight_inquiries');
        Schema::table('maritime_bookings', function (Blueprint $table) {
            $table->dropColumn(['partner_freight_payment_timing', 'partner_freight_paid_at', 'partner_freight_paid_xaf']);
        });
        // Les délais inconnus restent null : aucune durée fictive lors du rollback.
    }
};
