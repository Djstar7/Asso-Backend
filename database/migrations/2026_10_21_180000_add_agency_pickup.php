<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_agencies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deliverer_company_id')->constrained()->restrictOnDelete();
            $table->string('code', 40)->unique();
            $table->string('name', 150);
            $table->string('city', 120);
            $table->string('address');
            $table->string('phone', 40);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('delivery_agency_id')->nullable()->constrained()->restrictOnDelete();
            $table->json('pickup_agency_snapshot')->nullable();
            $table->timestamp('agency_collected_at')->nullable();
            $table->foreignId('agency_collected_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('delivery_agency_id');
            $table->dropConstrainedForeignId('agency_collected_by');
            $table->dropColumn(['pickup_agency_snapshot', 'agency_collected_at']);
        });
        Schema::dropIfExists('delivery_agencies');
    }
};
