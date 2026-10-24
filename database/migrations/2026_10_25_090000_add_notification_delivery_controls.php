<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->json('notification_preferences')->nullable();
        });
        Schema::table('notifications', function (Blueprint $t) {
            $t->string('category', 24)->default('system');
            $t->string('push_status', 24)->default('stored');
            $t->string('event_key', 64)->nullable();
            $t->string('fingerprint', 64)->nullable();
            $t->unique(['user_id', 'event_key']);
            $t->index(['user_id', 'fingerprint', 'created_at'], 'notifications_recent_events');
        });
        Schema::create('notification_push_deliveries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('notification_id')->constrained()->cascadeOnDelete();
            $t->foreignId('device_token_id')->nullable()->constrained()->nullOnDelete();
            $t->string('status', 24)->default('pending');
            $t->unsignedSmallInteger('attempts')->default(0);
            $t->timestamp('next_attempt_at')->nullable()->index();
            $t->timestamp('accepted_at')->nullable();
            $t->string('error_code')->nullable();
            $t->timestamps();
            $t->unique(['notification_id', 'device_token_id'], 'notification_device_delivery');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_push_deliveries');
        Schema::table('notifications', function (Blueprint $t) {
            $t->dropUnique(['user_id', 'event_key']);
            $t->dropIndex('notifications_recent_events');
            $t->dropColumn(['category', 'push_status', 'event_key', 'fingerprint']);
        });
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('notification_preferences'));
    }
};
