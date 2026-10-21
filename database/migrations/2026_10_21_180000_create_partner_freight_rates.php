<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_freight_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deliverer_company_id')->constrained()->cascadeOnDelete();
            $table->char('origin_country', 2);
            $table->char('destination_country', 2);
            $table->string('transport_mode', 10);
            $table->string('cargo_category', 20);
            $table->string('rate_unit', 10);
            $table->decimal('rate_amount', 20, 2);
            $table->decimal('public_rate_amount', 20, 2)->nullable();
            $table->unsignedSmallInteger('frequency_days')->nullable();
            $table->char('currency', 3)->default('XAF');
            $table->unsignedSmallInteger('transit_days_min');
            $table->unsignedSmallInteger('transit_days_max');
            $table->string('freight_payment_timing', 20)->default('departure');
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['deliverer_company_id', 'origin_country', 'destination_country', 'transport_mode', 'cargo_category'], 'partner_freight_rate_lane_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_freight_rates');
    }
};
