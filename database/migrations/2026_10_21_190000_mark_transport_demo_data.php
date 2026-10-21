<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maritime_hubs', function (Blueprint $table) {
            $table->boolean('is_demo')->default(false);
        });
        Schema::table('maritime_departures', function (Blueprint $table) {
            $table->boolean('is_demo')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('maritime_departures', fn (Blueprint $table) => $table->dropColumn('is_demo'));
        Schema::table('maritime_hubs', fn (Blueprint $table) => $table->dropColumn('is_demo'));
    }
};
