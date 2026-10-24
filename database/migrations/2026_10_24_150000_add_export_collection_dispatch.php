<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['deliverer_sync_codes', 'deliverer_code_syncs'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->foreignId('delivery_agency_id')->nullable()->constrained('delivery_agencies')->restrictOnDelete());
        }
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('collection_agency_id')->nullable()->constrained('delivery_agencies')->restrictOnDelete();
            $table->timestamp('collection_completed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('collection_agency_id');
            $table->dropColumn('collection_completed_at');
        });
        foreach (['deliverer_code_syncs', 'deliverer_sync_codes'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropConstrainedForeignId('delivery_agency_id'));
        }
    }
};
