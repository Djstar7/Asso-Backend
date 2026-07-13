<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\ServiceConfiguration;

class ServiceConfigurationSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Nexaah SMS Configuration
        ServiceConfiguration::updateOrCreate(
            ['service_name' => ServiceConfiguration::SERVICE_NEXAAH_SMS],
            [
                'is_active' => false, // Inactive by default until configured
                'description' => 'Configuration pour l\'envoi de SMS via Nexah API',
                'configuration' => [
                    'base_url' => 'https://smsvas.com/bulk/public/index.php/api/v1',
                    'send_endpoint' => '/sendsms',
                    'credits_endpoint' => '/smscredit',
                    'user' => '', // To be filled by admin (email)
                    'password' => '', // To be filled by admin
                    'sender_id' => 'ASSO', // Default sender ID (max 11 characters)
                ],
            ]
        );

        // KPay Configuration
        ServiceConfiguration::updateOrCreate(
            ['service_name' => ServiceConfiguration::SERVICE_KPAY],
            [
                'service_type' => 'payment',
                'is_active' => true,
                'description' => 'KPay - Paiements et retraits Mobile Money',
                'configuration' => [
                    'base_url' => 'https://admin.kpay.site',
                    'mode' => 'sandbox',        // sandbox | live
                    'api_key' => '',             // kpay_test_... / kpay_live_... (à renseigner)
                    'secret_key' => '',          // sk_test_... / sk_live_...       (à renseigner)
                    'webhook_secret' => '',      // secret de signature des webhooks
                ],
            ]
        );

        // FedaPay Configuration
        ServiceConfiguration::updateOrCreate(
            ['service_name' => ServiceConfiguration::SERVICE_FEDAPAY],
            [
                'is_active' => false,
                'description' => 'Configuration pour les paiements via FedaPay',
                'configuration' => [
                    'public_key' => '', // To be filled by admin
                    'secret_key' => '', // To be filled by admin
                    'environment' => 'sandbox', // sandbox or live
                    'callback_url' => env('APP_URL') . '/api/v1/payments/webhook/fedapay',
                ],
            ]
        );

        // PayPal Configuration
        ServiceConfiguration::updateOrCreate(
            ['service_name' => ServiceConfiguration::SERVICE_PAYPAL],
            [
                'is_active' => false,
                'description' => 'Configuration pour les paiements via PayPal',
                'configuration' => [
                    'mode' => 'sandbox', // sandbox or live
                    'client_id' => '', // To be filled by admin
                    'client_secret' => '', // To be filled by admin
                    'currency' => 'XAF',
                    'return_url' => env('APP_URL') . '/payment/success',
                    'cancel_url' => env('APP_URL') . '/payment/cancel',
                ],
            ]
        );

        // WhatsApp Business Configuration
        ServiceConfiguration::updateOrCreate(
            ['service_name' => ServiceConfiguration::SERVICE_WHATSAPP],
            [
                'is_active' => false,
                'description' => 'Configuration pour les notifications via WhatsApp Business API',
                'configuration' => [
                    'api_token' => '', // To be filled by admin
                    'phone_number_id' => '', // To be filled by admin
                    'api_version' => 'v18.0',
                    'template_name' => 'otp_message',
                    'language' => 'fr',
                ],
            ]
        );

        $this->command->info('✓ Service configurations seeded successfully!');
        $this->command->warn('⚠ Remember to configure each service with valid credentials.');
    }
}
