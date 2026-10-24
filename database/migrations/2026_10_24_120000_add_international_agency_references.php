<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_agencies', function (Blueprint $table) {
            $table->string('country', 2)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 11, 7)->nullable();
            $table->boolean('is_international_reference')->default(false);
            $table->boolean('is_provisional')->default(false);
            $table->string('source_url', 512)->nullable();
            $table->string('source_dataset', 80)->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->index(['deliverer_company_id', 'country']);
        });
    }

    public function down(): void
    {
        Schema::table('delivery_agencies', function (Blueprint $table) {
            $table->dropIndex(['deliverer_company_id', 'country']);
            $table->dropColumn(['country', 'latitude', 'longitude', 'is_international_reference', 'is_provisional', 'source_url', 'source_dataset', 'verified_at']);
        });
    }
};
