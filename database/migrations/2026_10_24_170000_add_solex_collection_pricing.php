<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_agencies', fn (Blueprint $table) => $table->json('collection_pricing')->nullable());
    }

    public function down(): void
    {
        Schema::table('delivery_agencies', fn (Blueprint $table) => $table->dropColumn('collection_pricing'));
    }
};
