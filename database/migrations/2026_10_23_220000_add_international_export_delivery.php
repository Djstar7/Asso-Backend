<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_routes', function (Blueprint $table) {
            $table->json('export_config')->nullable();
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->string('recipient_name', 150)->nullable();
            $table->string('delivery_postal_code', 30)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('delivery_routes', fn (Blueprint $table) => $table->dropColumn('export_config'));
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn(['recipient_name', 'delivery_postal_code']));
    }
};
