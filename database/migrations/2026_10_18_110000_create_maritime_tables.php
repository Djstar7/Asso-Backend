<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Diaspo Maritime : des partenaires maritimes (deliverer_companies, type
 * « maritime ») assurent des départs fixes entre leurs QG. L'admin crée les
 * départs (date, capacité, prix du kilo) ; l'expéditeur réserve des kilos,
 * paie, puis dépose son colis au QG de départ avant la date limite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maritime_hubs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deliverer_company_id')->constrained('deliverer_companies')->cascadeOnDelete();
            $table->string('name');
            $table->char('country', 2);
            $table->string('city', 120);
            $table->string('address');
            $table->string('phone', 30)->nullable();
            $table->string('opening_hours')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('maritime_departures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deliverer_company_id')->constrained('deliverer_companies')->cascadeOnDelete();
            $table->foreignId('origin_hub_id')->constrained('maritime_hubs');
            $table->foreignId('destination_hub_id')->constrained('maritime_hubs');
            $table->date('departure_date');
            $table->date('estimated_arrival_date')->nullable();
            $table->decimal('capacity_kg', 10, 2);
            $table->decimal('remaining_kg', 10, 2);
            // Prix du partenaire ; le client paie ce prix majoré de la commission ASSO.
            $table->decimal('price_per_kg', 10, 2);
            $table->char('currency', 3)->default('EUR');
            $table->string('status', 20)->default('open'); // open|closed|departed|arrived|cancelled
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['status', 'departure_date']);
        });

        Schema::create('maritime_bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('maritime_departure_id')->constrained('maritime_departures');
            $table->foreignId('sender_user_id')->constrained('users');

            $table->decimal('kg_booked', 10, 2);
            $table->unsignedInteger('parcels_count')->default(1);

            $table->string('sender_first_name', 100);
            $table->string('sender_last_name', 100);
            $table->string('sender_phone', 30);
            $table->string('emergency_phone', 30);
            $table->string('recipient_first_name', 100);
            $table->string('recipient_last_name', 100);
            $table->char('recipient_country', 2);
            $table->string('recipient_phone', 30);

            $table->foreignId('parcel_nature_id')->nullable()->constrained('parcel_natures')->nullOnDelete();
            $table->decimal('declared_value', 12, 2);
            $table->decimal('insurance_amount', 12, 2)->default(0);

            // Montants dans la devise du départ : fret partenaire, commission ASSO, total payé.
            $table->decimal('price_per_kg', 10, 2);
            $table->decimal('freight_subtotal', 12, 2);
            $table->decimal('commission_amount', 12, 2);
            $table->decimal('total_price', 12, 2);
            $table->char('currency', 3);

            $table->date('dropoff_deadline');
            $table->string('confirmation_code', 6);
            // pending|paid|dropped_off|shipped|arrived|completed|cancelled
            $table->string('status', 20)->default('pending');

            $table->string('payment_status', 20)->default('pending'); // pending|completed|refunded
            $table->string('payment_method', 20)->nullable();
            $table->string('payment_reference')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->decimal('refunded_amount', 12, 2)->nullable(); // en XAF (Wallet)
            $table->timestamp('refunded_at')->nullable();

            $table->timestamp('dropped_off_at')->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('arrived_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('cancel_reason')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('admin_notes')->nullable();
            $table->timestamps();

            $table->index(['status', 'dropoff_deadline']);
            $table->index('payment_reference');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maritime_bookings');
        Schema::dropIfExists('maritime_departures');
        Schema::dropIfExists('maritime_hubs');
    }
};
