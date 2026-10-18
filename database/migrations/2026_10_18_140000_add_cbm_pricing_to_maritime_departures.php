<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le transporteur maritime facture au m³ (ex. Paris → Douala : 900 € le m³).
 * L'expéditeur, lui, saisit des kilos : on garde le tarif au m³ et la
 * conversion kg → m³ du départ, et le prix au kilo en découle.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maritime_departures', function (Blueprint $table) {
            $table->decimal('price_per_cbm', 12, 2)->nullable()->after('price_per_kg');
            $table->decimal('kg_per_cbm', 10, 2)->nullable()->after('price_per_cbm');
        });
        Schema::table('maritime_bookings', function (Blueprint $table) {
            $table->decimal('volume_cbm', 10, 4)->nullable()->after('kg_booked');
        });
    }

    public function down(): void
    {
        Schema::table('maritime_bookings', fn (Blueprint $table) => $table->dropColumn('volume_cbm'));
        Schema::table('maritime_departures', fn (Blueprint $table) => $table->dropColumn(['price_per_cbm', 'kg_per_cbm']));
    }
};
