<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ElgioPay : Orange Money et MTN MoMo (Cameroun), en alternative exclusive à
 * KPay (cf. MobileMoneyGateway). Stripe (carte, Connect) n'est pas concerné.
 *
 * Crée la configuration de service « elgiopay », préremplie depuis le .env
 * (ELGIOPAY_*) et DÉSACTIVÉE tant que l'admin ne l'active pas (Paramètres >
 * Paiements). Ne touche jamais une configuration déjà enregistrée.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('service_configurations')
            || DB::table('service_configurations')->where('service_name', 'elgiopay')->exists()) {
            return;
        }

        DB::table('service_configurations')->insert([
            'service_name' => 'elgiopay',
            'service_type' => 'payment',
            'is_active' => false,
            'configuration' => json_encode([
                'mode' => config('services.elgiopay.mode', 'live'),          // sandbox | live
                // C'est la clé PUBLIQUE qui authentifie l'API (la secrète y renvoie 401).
                'public_key' => (string) config('services.elgiopay.public_key'),
                // Sert à vérifier la signature des webhooks, à défaut du secret dédié.
                'secret_key' => (string) config('services.elgiopay.secret_key'),
                'webhook_secret' => (string) config('services.elgiopay.webhook_secret'),
                'webhook_url' => '',
            ]),
            'description' => 'ElgioPay - Mobile Money Cameroun (MTN, Orange)',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('service_configurations')->where('service_name', 'elgiopay')->delete();
    }
};
