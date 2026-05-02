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
        Schema::table('delivery_pricelists', function (Blueprint $table) {
            $table->decimal('asso_commission', 10, 2)->default(0)->after('pricing_data');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('delivery_pricelists', function (Blueprint $table) {
            $table->dropColumn('asso_commission');
        });
    }
};
