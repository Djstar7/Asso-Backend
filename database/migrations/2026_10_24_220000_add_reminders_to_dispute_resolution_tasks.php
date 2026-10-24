<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dispute_resolution_tasks', function (Blueprint $table) {
            $table->timestamp('reminder_due_at')->nullable()->index();
            $table->timestamp('reminded_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('dispute_resolution_tasks', function (Blueprint $table) {
            $table->dropIndex(['reminder_due_at']);
            $table->dropColumn(['reminder_due_at', 'reminded_at']);
        });
    }
};
