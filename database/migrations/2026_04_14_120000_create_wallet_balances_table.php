<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Soldes wallet KPay par devise (multi-devise).
 * Chaque ligne = solde d'un utilisateur dans une devise donnée.
 * Le solde XAF historique (users.kpay_wallet_balance) est migré ici.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('currency', 3);                    // XAF, XOF, CDF, KES...
            $table->decimal('balance', 20, 2)->default(0);
            $table->decimal('locked_balance', 20, 2)->default(0); // fonds bloqués (escrow)
            $table->timestamps();

            $table->unique(['user_id', 'currency']);
        });

        // Migrer les soldes XAF existants (kpay_wallet_balance / locked_kpay_balance).
        if (Schema::hasColumn('users', 'kpay_wallet_balance')) {
            $users = DB::table('users')
                ->select('id', 'kpay_wallet_balance', 'locked_kpay_balance')
                ->where(function ($q) {
                    $q->where('kpay_wallet_balance', '>', 0)
                      ->orWhere('locked_kpay_balance', '>', 0);
                })
                ->get();

            foreach ($users as $u) {
                DB::table('wallet_balances')->updateOrInsert(
                    ['user_id' => $u->id, 'currency' => 'XAF'],
                    [
                        'balance' => $u->kpay_wallet_balance ?? 0,
                        'locked_balance' => $u->locked_kpay_balance ?? 0,
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_balances');
    }
};
