<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('device_tokens', fn (Blueprint $table) => $table->unsignedBigInteger('access_token_id')->nullable()->index());
    }

    public function down(): void
    {
        Schema::table('device_tokens', function (Blueprint $table) {
            $table->dropIndex(['access_token_id']);
            $table->dropColumn('access_token_id');
        });
    }
};
