<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Setting;

class PaymentSettingsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $settings = [
            // ============================================
            // KPay Settings (API v2)
            // ============================================
            [
                'key' => 'kpay_enabled',
                'value' => '0',
                'group' => 'payment',
                'type' => 'boolean',
                'description' => 'Activer ou désactiver KPay',
            ],
            [
                'key' => 'kpay_base_url',
                'value' => 'https://api-v2.kpay.com',
                'group' => 'payment',
                'type' => 'string',
                'description' => 'URL de base de l\'API KPay v2',
            ],
            [
                'key' => 'kpay_app_key',
                'value' => '',
                'group' => 'payment',
                'type' => 'string',
                'description' => 'App Key KPay',
            ],
            [
                'key' => 'kpay_secret_key',
                'value' => '',
                'group' => 'payment',
                'type' => 'string',
                'description' => 'Secret Key KPay',
            ],
            [
                'key' => 'kpay_callback_url',
                'value' => url('/api/webhooks/kpay'),
                'group' => 'payment',
                'type' => 'string',
                'description' => 'URL de callback pour les notifications KPay',
            ],
            // Paramètres avancés KPay
            [
                'key' => 'kpay_timeout_init',
                'value' => '30',
                'group' => 'payment',
                'type' => 'integer',
                'description' => 'Timeout init paiement (secondes)',
            ],
            [
                'key' => 'kpay_timeout_verify',
                'value' => '30',
                'group' => 'payment',
                'type' => 'integer',
                'description' => 'Timeout vérification statut (secondes)',
            ],
            [
                'key' => 'kpay_timeout_token',
                'value' => '30',
                'group' => 'payment',
                'type' => 'integer',
                'description' => 'Timeout token (secondes)',
            ],
            [
                'key' => 'kpay_token_cache_duration',
                'value' => '3000',
                'group' => 'payment',
                'type' => 'integer',
                'description' => 'Durée cache token (secondes) - 3000s = 50 min',
            ],
            [
                'key' => 'kpay_retry_attempts',
                'value' => '5',
                'group' => 'payment',
                'type' => 'integer',
                'description' => 'Nombre de tentatives',
            ],
            [
                'key' => 'kpay_retry_delay',
                'value' => '0.5',
                'group' => 'payment',
                'type' => 'string',
                'description' => 'Délai entre tentatives (secondes)',
            ],

            // ============================================
            // PayPal Settings
            // ============================================
            [
                'key' => 'paypal_enabled',
                'value' => '0',
                'group' => 'payment',
                'type' => 'boolean',
                'description' => 'Activer ou désactiver PayPal',
            ],
            [
                'key' => 'paypal_mode',
                'value' => 'sandbox',
                'group' => 'payment',
                'type' => 'string',
                'description' => 'Mode d\'exécution (sandbox pour tests, live pour production)',
            ],
            [
                'key' => 'paypal_client_id',
                'value' => '',
                'group' => 'payment',
                'type' => 'string',
                'description' => 'Client ID PayPal',
            ],
            [
                'key' => 'paypal_client_secret',
                'value' => '',
                'group' => 'payment',
                'type' => 'string',
                'description' => 'Client Secret PayPal',
            ],
            [
                'key' => 'paypal_currency',
                'value' => 'USD',
                'group' => 'payment',
                'type' => 'string',
                'description' => 'Devise utilisée pour tous les paiements PayPal',
            ],
        ];

        foreach ($settings as $setting) {
            Setting::updateOrCreate(
                ['key' => $setting['key']],
                $setting
            );
        }

        $this->command->info('✅ Payment settings seeded successfully!');
        $this->command->info('   - KPay: 7 settings');
        $this->command->info('   - PayPal: 6 settings');
    }
}
