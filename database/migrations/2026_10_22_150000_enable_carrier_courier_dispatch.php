<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('carrier_courier_requested_at')->nullable()->index();
            $table->string('carrier_handover_code', 6)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['carrier_courier_requested_at']);
            $table->dropColumn(['carrier_courier_requested_at', 'carrier_handover_code']);
        });
    }
};
