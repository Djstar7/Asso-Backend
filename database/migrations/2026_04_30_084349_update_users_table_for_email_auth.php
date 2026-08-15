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
        Schema::table('users', function (Blueprint $table) {
            // Make email nullable to allow phone-only authentication
            $table->string('email')->nullable()->change();

            // Add index on phone for faster lookups
            $table->index('phone');

            // Ensure phone is unique when not null
            $table->unique('phone');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Revert email to required
            $table->string('email')->nullable(false)->change();

            // Drop indexes
            $table->dropIndex(['phone']);
            $table->dropUnique(['phone']);
        });
    }
};
