<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Commerciaux inscrits depuis l'application : l'utilisateur accepte les modalités
 * (date conservée comme preuve) et reçoit son code. `source` distingue ces
 * inscriptions des commerciaux créés par l'admin.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_agents', function (Blueprint $table) {
            $table->string('source', 16)->default('admin')->after('is_active');
            $table->timestamp('terms_accepted_at')->nullable()->after('source');
        });
    }

    public function down(): void
    {
        Schema::table('sales_agents', function (Blueprint $table) {
            $table->dropColumn(['source', 'terms_accepted_at']);
        });
    }
};
