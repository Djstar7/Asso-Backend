<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Migration one-shot des anciens soldes KPay (colonnes legacy) vers la table
 * multi-devise `wallet_balances` (devise XAF).
 *
 * Contexte : `WalletService` écrivait autrefois le solde KPay dans
 * `users.kpay_wallet_balance` / `users.locked_kpay_balance`, alors que l'affichage
 * et les retraits lisent `wallet_balances`. Les mouvements passés (dont les crédits
 * vendeurs à la validation) sont donc « bloqués » dans des colonnes mortes.
 *
 * Cette migration reverse ces montants dans `wallet_balances` (XAF) puis remet les
 * colonnes legacy à 0 (empêche toute double-migration si la migration est rejouée
 * après un rollback). Idempotente en pratique : une fois les colonnes à 0, un second
 * passage n'a plus rien à transférer.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('users')
            ->where(function ($q) {
                $q->where('kpay_wallet_balance', '>', 0)
                  ->orWhere('locked_kpay_balance', '>', 0);
            })
            ->select('id', 'kpay_wallet_balance', 'locked_kpay_balance')
            ->orderBy('id')
            ->chunkById(200, function ($users) use ($now) {
                foreach ($users as $user) {
                    $balance = (float) ($user->kpay_wallet_balance ?? 0);
                    $locked = (float) ($user->locked_kpay_balance ?? 0);

                    if ($balance <= 0 && $locked <= 0) {
                        continue;
                    }

                    DB::transaction(function () use ($user, $balance, $locked, $now) {
                        $existing = DB::table('wallet_balances')
                            ->where('user_id', $user->id)
                            ->where('currency', 'XAF')
                            ->lockForUpdate()
                            ->first();

                        if ($existing) {
                            DB::table('wallet_balances')
                                ->where('id', $existing->id)
                                ->update([
                                    'balance' => (float) $existing->balance + $balance,
                                    'locked_balance' => (float) $existing->locked_balance + $locked,
                                    'updated_at' => $now,
                                ]);
                        } else {
                            DB::table('wallet_balances')->insert([
                                'user_id' => $user->id,
                                'currency' => 'XAF',
                                'balance' => $balance,
                                'locked_balance' => $locked,
                                'created_at' => $now,
                                'updated_at' => $now,
                            ]);
                        }

                        // Neutraliser la source legacy pour éviter tout double-comptage.
                        DB::table('users')
                            ->where('id', $user->id)
                            ->update([
                                'kpay_wallet_balance' => 0,
                                'locked_kpay_balance' => 0,
                            ]);
                    });
                }
            });
    }

    /**
     * Migration de données non réversible : les colonnes legacy ont été remises à 0
     * et le montant vit désormais dans `wallet_balances`. On ne rejoue pas l'inverse
     * (risque de double-comptage). Rollback = no-op documenté.
     */
    public function down(): void
    {
        // Intentionnellement vide : voir le PHPDoc ci-dessus.
    }
};
