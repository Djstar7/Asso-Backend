<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            // Existing paths remain readable until documents:secure-storage migrates them.
            $table->string('file_disk')->default('public');
            $table->string('legacy_public_path')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('documents', fn (Blueprint $table) => $table->dropColumn(['file_disk', 'legacy_public_path']));
    }
};
