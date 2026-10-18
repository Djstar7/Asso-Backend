<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maritime_bookings', function (Blueprint $table) {
            $table->boolean('insurance_opt_in')->default(false)->after('declared_value');
            $table->decimal('insurance_rate_applied', 8, 4)->default(0)->after('insurance_opt_in');
            $table->decimal('insurance_quote_amount', 12, 2)->default(0)->after('insurance_rate_applied');
            $table->string('insurance_status', 24)->default('not_opted_in')->after('insurance_quote_amount');
        });

        Schema::create('maritime_booking_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('maritime_booking_id')->constrained('maritime_bookings')->cascadeOnDelete();
            $table->string('event', 40);
            $table->string('status', 24);
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['maritime_booking_id', 'created_at']);
        });

        Schema::create('maritime_indemnity_claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('maritime_booking_id')->unique()->constrained('maritime_bookings')->cascadeOnDelete();
            $table->string('status', 24)->default('reported');
            $table->text('customer_reason');
            $table->text('loss_confirmation_reason')->nullable();
            $table->text('decision_reason')->nullable();
            $table->decimal('payout_amount_xaf', 20, 2)->nullable();
            $table->string('payout_operation_reference')->nullable()->unique();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('loss_confirmed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });

        Schema::create('maritime_claim_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('maritime_indemnity_claim_id')->constrained('maritime_indemnity_claims')->cascadeOnDelete();
            $table->string('action', 32);
            $table->string('from_status', 24)->nullable();
            $table->string('to_status', 24);
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->decimal('amount_xaf', 20, 2)->nullable();
            $table->string('operation_reference')->nullable()->unique();
            $table->timestamps();
            $table->index(['maritime_indemnity_claim_id', 'created_at']);
        });

        Schema::create('conversation_maritime_booking', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('conversations')->cascadeOnDelete();
            $table->foreignId('maritime_booking_id')->constrained('maritime_bookings')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['conversation_id', 'maritime_booking_id'], 'conversation_maritime_booking_unique');
            $table->index('maritime_booking_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_maritime_booking');
        Schema::dropIfExists('maritime_claim_histories');
        Schema::dropIfExists('maritime_indemnity_claims');
        Schema::dropIfExists('maritime_booking_histories');
        Schema::table('maritime_bookings', function (Blueprint $table) {
            $table->dropColumn(['insurance_opt_in', 'insurance_rate_applied', 'insurance_quote_amount', 'insurance_status']);
        });
    }
};
