<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Migration Freemopay -> KPay :
 *  - renomme les colonnes de solde wallet (users) et les références de retrait
 *    (platform_withdrawals),
 *  - bascule les valeurs de provider 'freemopay' -> 'kpay',
 *  - remplace la configuration de service 'freemopay' par 'kpay'.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. users : soldes wallet
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'freemopay_wallet_balance') && !Schema::hasColumn('users', 'kpay_wallet_balance')) {
                $table->renameColumn('freemopay_wallet_balance', 'kpay_wallet_balance');
            }
            if (Schema::hasColumn('users', 'locked_freemopay_balance') && !Schema::hasColumn('users', 'locked_kpay_balance')) {
                $table->renameColumn('locked_freemopay_balance', 'locked_kpay_balance');
            }
        });

        // 2. platform_withdrawals : références provider
        Schema::table('platform_withdrawals', function (Blueprint $table) {
            if (Schema::hasColumn('platform_withdrawals', 'freemopay_reference') && !Schema::hasColumn('platform_withdrawals', 'kpay_reference')) {
                $table->renameColumn('freemopay_reference', 'kpay_reference');
            }
            if (Schema::hasColumn('platform_withdrawals', 'freemopay_response') && !Schema::hasColumn('platform_withdrawals', 'kpay_response')) {
                $table->renameColumn('freemopay_response', 'kpay_response');
            }
        });

        // 3. Basculer les valeurs de provider persistées
        $isPgsql = DB::getDriverName() === 'pgsql';
        if (Schema::hasTable('wallet_transactions')) {
            DB::table('wallet_transactions')->where('provider', 'freemopay')->update(['provider' => 'kpay']);
            if ($isPgsql) {
                DB::statement("ALTER TABLE wallet_transactions ALTER COLUMN provider SET DEFAULT 'kpay'");
            }
        }
        if (Schema::hasTable('platform_withdrawals')) {
            DB::table('platform_withdrawals')->where('provider', 'freemopay')->update(['provider' => 'kpay']);
            if ($isPgsql) {
                DB::statement("ALTER TABLE platform_withdrawals ALTER COLUMN provider SET DEFAULT 'kpay'");
            }
        }

        // 4. Configuration de service : remplacer freemopay par kpay
        if (Schema::hasTable('service_configurations')) {
            DB::table('service_configurations')->where('service_name', 'freemopay')->delete();

            $exists = DB::table('service_configurations')->where('service_name', 'kpay')->exists();
            if (!$exists) {
                DB::table('service_configurations')->insert([
                    'service_name' => 'kpay',
                    'service_type' => 'payment',
                    'is_active' => true,
                    'configuration' => json_encode([
                        'base_url' => 'https://admin.kpay.site',
                        'mode' => 'sandbox',        // sandbox | live
                        'api_key' => '',             // kpay_test_... / kpay_live_... (à renseigner)
                        'secret_key' => '',          // sk_test_... / sk_live_...       (à renseigner)
                        'webhook_secret' => '',      // secret de signature des webhooks
                    ]),
                    'description' => 'KPay - Paiements et retraits Mobile Money',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'kpay_wallet_balance') && !Schema::hasColumn('users', 'freemopay_wallet_balance')) {
                $table->renameColumn('kpay_wallet_balance', 'freemopay_wallet_balance');
            }
            if (Schema::hasColumn('users', 'locked_kpay_balance') && !Schema::hasColumn('users', 'locked_freemopay_balance')) {
                $table->renameColumn('locked_kpay_balance', 'locked_freemopay_balance');
            }
        });

        Schema::table('platform_withdrawals', function (Blueprint $table) {
            if (Schema::hasColumn('platform_withdrawals', 'kpay_reference')) {
                $table->renameColumn('kpay_reference', 'freemopay_reference');
            }
            if (Schema::hasColumn('platform_withdrawals', 'kpay_response')) {
                $table->renameColumn('kpay_response', 'freemopay_response');
            }
        });

        $isPgsql = DB::getDriverName() === 'pgsql';
        if (Schema::hasTable('wallet_transactions')) {
            DB::table('wallet_transactions')->where('provider', 'kpay')->update(['provider' => 'freemopay']);
            if ($isPgsql) {
                DB::statement("ALTER TABLE wallet_transactions ALTER COLUMN provider SET DEFAULT 'freemopay'");
            }
        }
        if (Schema::hasTable('platform_withdrawals')) {
            DB::table('platform_withdrawals')->where('provider', 'kpay')->update(['provider' => 'freemopay']);
            if ($isPgsql) {
                DB::statement("ALTER TABLE platform_withdrawals ALTER COLUMN provider SET DEFAULT 'freemopay'");
            }
        }

        if (Schema::hasTable('service_configurations')) {
            DB::table('service_configurations')->where('service_name', 'kpay')->delete();
        }
    }
};
