<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_digest_items', function (Blueprint $t) {
            $t->id();
            $t->string('event_key', 64)->unique();
            $t->json('texts');
            $t->json('data');
            $t->timestamps();
        });
        Schema::table('real_estate_alerts', fn (Blueprint $t) => $t->timestamp('last_dispatched_at')->nullable());
    }

    public function down(): void
    {
        Schema::table('real_estate_alerts', fn (Blueprint $t) => $t->dropColumn('last_dispatched_at'));
        Schema::dropIfExists('notification_digest_items');
    }
};
