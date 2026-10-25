<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_videos', fn (Blueprint $table) => $table->uuid('upload_id')->nullable()->unique());
    }

    public function down(): void
    {
        Schema::table('product_videos', function (Blueprint $table) {
            $table->dropUnique(['upload_id']);
            $table->dropColumn('upload_id');
        });
    }
};
