<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Détail du montant payé (avance ou acompte, commission) pour le contrat et la facture. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('real_estate_reservations', function (Blueprint $table) {
            $table->unsignedBigInteger('base_amount')->default(0)->after('displayed_unit_price');
            $table->unsignedBigInteger('commission_amount')->default(0)->after('base_amount');
            $table->decimal('deposit_percent', 5, 2)->nullable()->after('commission_amount');
        });
    }

    public function down(): void
    {
        Schema::table('real_estate_reservations', function (Blueprint $table) {
            $table->dropColumn(['base_amount', 'commission_amount', 'deposit_percent']);
        });
    }
};
