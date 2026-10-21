<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_tracking_events', function (Blueprint $table) {
            $table->string('confirmation_contact', 150)->nullable();
            $table->string('confirmation_reference', 150)->nullable();
            $table->string('confirmation_channel', 30)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('order_tracking_events', fn (Blueprint $table) => $table->dropColumn([
            'confirmation_contact', 'confirmation_reference', 'confirmation_channel',
        ]));
    }
};
