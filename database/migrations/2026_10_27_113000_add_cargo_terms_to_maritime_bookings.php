<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maritime_bookings', function (Blueprint $table) {
            $table->string('cargo_category')->nullable();
            $table->json('freight_rate_snapshot')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('maritime_bookings', fn (Blueprint $table) => $table->dropColumn(['cargo_category', 'freight_rate_snapshot']));
    }
};
