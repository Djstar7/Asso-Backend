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
        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('base_delivery_price', 10, 2)->default(0)->after('delivery_fee')->comment('Prix de base du livreur');
            $table->decimal('delivery_commission', 10, 2)->default(0)->after('base_delivery_price')->comment('Commission ASSO');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['base_delivery_price', 'delivery_commission']);
        });
    }
};
