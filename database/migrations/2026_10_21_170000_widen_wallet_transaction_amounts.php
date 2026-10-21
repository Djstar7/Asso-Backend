<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Même capacité que wallet_balances : chaque mouvement doit pouvoir être tracé.
        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->decimal('amount', 20, 2)->change();
            $table->decimal('balance_before', 20, 2)->change();
            $table->decimal('balance_after', 20, 2)->change();
        });
    }

    public function down(): void
    {
        $tooLarge = DB::table('wallet_transactions')->where(function ($query) {
            foreach (['amount', 'balance_before', 'balance_after'] as $column) {
                $query->orWhere($column, '>', 99999999.99)->orWhere($column, '<', -99999999.99);
            }
        })->exists();
        if ($tooLarge) {
            throw new RuntimeException('Impossible de réduire la précision : des transactions dépassent la capacité du format initial.');
        }
        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->decimal('amount', 10, 2)->change();
            $table->decimal('balance_before', 10, 2)->change();
            $table->decimal('balance_after', 10, 2)->change();
        });
    }
};
