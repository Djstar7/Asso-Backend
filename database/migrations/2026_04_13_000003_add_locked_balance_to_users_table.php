<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->decimal('locked_freemopay_balance', 15, 2)->default(0)->after('freemopay_wallet_balance');
            $table->decimal('locked_paypal_balance', 15, 2)->default(0)->after('paypal_wallet_balance');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['locked_freemopay_balance', 'locked_paypal_balance']);
        });
    }
};
