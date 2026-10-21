<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maritime_departures', function (Blueprint $table) {
            $table->foreignId('partner_freight_rate_id')->nullable()->constrained()->nullOnDelete();
            $table->json('freight_rate_snapshot')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('maritime_departures', function (Blueprint $table) {
            $table->dropConstrainedForeignId('partner_freight_rate_id');
            $table->dropColumn('freight_rate_snapshot');
        });
    }
};
