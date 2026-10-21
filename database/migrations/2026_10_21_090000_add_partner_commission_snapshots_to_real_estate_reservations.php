<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('real_estate_reservations', function (Blueprint $table) {
            $table->foreignId('partner_cabinet_id')
                ->nullable()
                ->after('payee_id')
                ->constrained('real_estate_cabinets')
                ->restrictOnDelete();
            $table->foreignId('partner_payee_id')
                ->nullable()
                ->after('partner_cabinet_id')
                ->constrained('users')
                ->restrictOnDelete();
            $table->unsignedBigInteger('partner_commission_amount')->default(0)->after('commission_amount');
        });
    }

    public function down(): void
    {
        Schema::table('real_estate_reservations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('partner_payee_id');
            $table->dropConstrainedForeignId('partner_cabinet_id');
            $table->dropColumn('partner_commission_amount');
        });
    }
};
