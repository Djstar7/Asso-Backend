<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Setting;

class SettingsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $settings = [
            // Paramètres généraux
            [
                'key' => 'app_name',
                'value' => 'ASSO',
                'type' => 'string',
                'group' => 'general',
                'description' => 'Nom de l\'application',
            ],
            [
                'key' => 'app_logo',
                'value' => '/images/logo.png',
                'type' => 'file',
                'group' => 'general',
                'description' => 'Logo de l\'application',
            ],
            [
                'key' => 'app_slogan',
                'value' => 'Votre plateforme de commerce solidaire',
                'type' => 'string',
                'group' => 'general',
                'description' => 'Slogan de l\'application',
            ],
            [
                'key' => 'app_description',
                'value' => 'ASSO est une plateforme innovante qui facilite les échanges et le commerce entre les membres de votre communauté.',
                'type' => 'text',
                'group' => 'general',
                'description' => 'Description de l\'application',
            ],
            [
                'key' => 'contact_email',
                'value' => 'contact@asso.com',
                'type' => 'string',
                'group' => 'general',
                'description' => 'Email de contact',
            ],
            [
                'key' => 'contact_phone',
                'value' => '+229 XX XX XX XX',
                'type' => 'string',
                'group' => 'general',
                'description' => 'Téléphone de contact',
            ],
            [
                'key' => 'contact_address',
                'value' => 'Cotonou, Bénin',
                'type' => 'string',
                'group' => 'general',
                'description' => 'Adresse physique',
            ],

            // Maintenance
            [
                'key' => 'maintenance_mode',
                'value' => '0',
                'type' => 'boolean',
                'group' => 'maintenance',
                'description' => 'Mode maintenance activé',
            ],
            [
                'key' => 'maintenance_message',
                'value' => 'Nous effectuons une maintenance technique. Nous serons de retour très bientôt.',
                'type' => 'text',
                'group' => 'maintenance',
                'description' => 'Message de maintenance',
            ],
            [
                'key' => 'maintenance_end_time',
                'value' => '',
                'type' => 'string',
                'group' => 'maintenance',
                'description' => 'Heure de fin estimée de la maintenance',
            ],

            // Paiement - PayPal
            [
                'key' => 'paypal_enabled',
                'value' => '0',
                'type' => 'boolean',
                'group' => 'payment',
                'description' => 'PayPal activé',
            ],
            [
                'key' => 'paypal_mode',
                'value' => 'sandbox',
                'type' => 'string',
                'group' => 'payment',
                'description' => 'Mode PayPal (sandbox ou live)',
            ],
            [
                'key' => 'paypal_client_id',
                'value' => '',
                'type' => 'string',
                'group' => 'payment',
                'description' => 'PayPal Client ID',
            ],
            [
                'key' => 'paypal_secret',
                'value' => '',
                'type' => 'string',
                'group' => 'payment',
                'description' => 'PayPal Secret Key',
            ],
            [
                'key' => 'paypal_webhook_id',
                'value' => '',
                'type' => 'string',
                'group' => 'payment',
                'description' => 'PayPal Webhook ID',
            ],
            [
                'key' => 'paypal_currency',
                'value' => 'USD',
                'type' => 'string',
                'group' => 'payment',
                'description' => 'Devise PayPal par défaut',
            ],

            // Paiement - Fedapay
            [
                'key' => 'fedapay_enabled',
                'value' => '0',
                'type' => 'boolean',
                'group' => 'payment',
                'description' => 'Fedapay activé',
            ],
            [
                'key' => 'fedapay_mode',
                'value' => 'sandbox',
                'type' => 'string',
                'group' => 'payment',
                'description' => 'Mode Fedapay (sandbox ou live)',
            ],
            [
                'key' => 'fedapay_public_key',
                'value' => '',
                'type' => 'string',
                'group' => 'payment',
                'description' => 'Fedapay Public Key',
            ],
            [
                'key' => 'fedapay_secret_key',
                'value' => '',
                'type' => 'string',
                'group' => 'payment',
                'description' => 'Fedapay Secret Key',
            ],
            [
                'key' => 'fedapay_webhook_secret',
                'value' => '',
                'type' => 'string',
                'group' => 'payment',
                'description' => 'Fedapay Webhook Secret',
            ],
            [
                'key' => 'fedapay_currency',
                'value' => 'XOF',
                'type' => 'string',
                'group' => 'payment',
                'description' => 'Devise Fedapay par défaut',
            ],
            [
                'key' => 'fedapay_callback_url',
                'value' => '',
                'type' => 'string',
                'group' => 'payment',
                'description' => 'URL de callback Fedapay',
            ],
            [
                'key' => 'fedapay_timeout',
                'value' => '300',
                'type' => 'integer',
                'group' => 'payment',
                'description' => 'Timeout Fedapay en secondes',
            ],
            [
                'key' => 'fedapay_auto_commission',
                'value' => '1',
                'type' => 'boolean',
                'group' => 'payment',
                'description' => 'Frais Fedapay automatiques',
            ],

            // SMS - Nexaah
            [
                'key' => 'nexaah_enabled',
                'value' => '0',
                'type' => 'boolean',
                'group' => 'sms',
                'description' => 'Nexaah SMS activé',
            ],
            [
                'key' => 'nexaah_api_key',
                'value' => '',
                'type' => 'string',
                'group' => 'sms',
                'description' => 'Nexaah SMS API Key',
            ],
            [
                'key' => 'nexaah_api_secret',
                'value' => '',
                'type' => 'string',
                'group' => 'sms',
                'description' => 'Nexaah SMS API Secret',
            ],
            [
                'key' => 'nexaah_account_sid',
                'value' => '',
                'type' => 'string',
                'group' => 'sms',
                'description' => 'Nexaah Account SID',
            ],
            [
                'key' => 'nexaah_sender_id',
                'value' => 'ASSO',
                'type' => 'string',
                'group' => 'sms',
                'description' => 'Nexaah Sender ID',
            ],
            [
                'key' => 'nexaah_base_url',
                'value' => 'https://api.nexaah.com/v1',
                'type' => 'string',
                'group' => 'sms',
                'description' => 'Nexaah API Base URL',
            ],
            [
                'key' => 'nexaah_country_code',
                'value' => '+229',
                'type' => 'string',
                'group' => 'sms',
                'description' => 'Code pays par défaut',
            ],
            [
                'key' => 'nexaah_timeout',
                'value' => '30',
                'type' => 'integer',
                'group' => 'sms',
                'description' => 'Timeout des requêtes API en secondes',
            ],

            // WhatsApp
            [
                'key' => 'whatsapp_enabled',
                'value' => '0',
                'type' => 'boolean',
                'group' => 'whatsapp',
                'description' => 'WhatsApp activé',
            ],
            [
                'key' => 'whatsapp_business_account_id',
                'value' => '',
                'type' => 'string',
                'group' => 'whatsapp',
                'description' => 'WhatsApp Business Account ID',
            ],
            [
                'key' => 'whatsapp_phone_number_id',
                'value' => '',
                'type' => 'string',
                'group' => 'whatsapp',
                'description' => 'WhatsApp Phone Number ID',
            ],
            [
                'key' => 'whatsapp_business_phone',
                'value' => '',
                'type' => 'string',
                'group' => 'whatsapp',
                'description' => 'Numéro WhatsApp Business',
            ],
            [
                'key' => 'whatsapp_display_name',
                'value' => 'ASSO',
                'type' => 'string',
                'group' => 'whatsapp',
                'description' => 'Nom d\'affichage WhatsApp',
            ],
            [
                'key' => 'whatsapp_access_token',
                'value' => '',
                'type' => 'string',
                'group' => 'whatsapp',
                'description' => 'WhatsApp Access Token (Permanent)',
            ],
            [
                'key' => 'whatsapp_app_id',
                'value' => '',
                'type' => 'string',
                'group' => 'whatsapp',
                'description' => 'Meta App ID',
            ],
            [
                'key' => 'whatsapp_app_secret',
                'value' => '',
                'type' => 'string',
                'group' => 'whatsapp',
                'description' => 'Meta App Secret',
            ],
            [
                'key' => 'whatsapp_api_version',
                'value' => 'v18.0',
                'type' => 'string',
                'group' => 'whatsapp',
                'description' => 'Version de l\'API Graph',
            ],
            [
                'key' => 'whatsapp_webhook_verify_token',
                'value' => '',
                'type' => 'string',
                'group' => 'whatsapp',
                'description' => 'Token de vérification Webhook',
            ],

            // Sécurité
            [
                'key' => 'two_factor_enabled',
                'value' => '0',
                'type' => 'boolean',
                'group' => 'security',
                'description' => 'Authentification à deux facteurs activée',
            ],
            [
                'key' => 'password_min_length',
                'value' => '8',
                'type' => 'integer',
                'group' => 'security',
                'description' => 'Longueur minimale du mot de passe',
            ],
            [
                'key' => 'session_timeout',
                'value' => '120',
                'type' => 'integer',
                'group' => 'security',
                'description' => 'Durée de session en minutes',
            ],

            // Notifications
            [
                'key' => 'email_notifications',
                'value' => '1',
                'type' => 'boolean',
                'group' => 'notifications',
                'description' => 'Notifications par email activées',
            ],
            [
                'key' => 'sms_notifications',
                'value' => '0',
                'type' => 'boolean',
                'group' => 'notifications',
                'description' => 'Notifications par SMS activées',
            ],
            [
                'key' => 'push_notifications',
                'value' => '0',
                'type' => 'boolean',
                'group' => 'notifications',
                'description' => 'Notifications push activées',
            ],

            // Système
            [
                'key' => 'timezone',
                'value' => 'Africa/Douala',
                'type' => 'string',
                'group' => 'system',
                'description' => 'Fuseau horaire d’affichage (back-office, documents)',
            ],
            [
                'key' => 'default_language',
                'value' => 'fr',
                'type' => 'string',
                'group' => 'system',
                'description' => 'Langue par défaut',
            ],
            [
                'key' => 'currency',
                'value' => 'XOF',
                'type' => 'string',
                'group' => 'system',
                'description' => 'Devise par défaut',
            ],
            [
                'key' => 'currency_symbol',
                'value' => 'FCFA',
                'type' => 'string',
                'group' => 'system',
                'description' => 'Symbole de la devise',
            ],
            [
                'key' => 'min_deposit_amount',
                'value' => '100',
                'type' => 'integer',
                'group' => 'system',
                'description' => 'Montant minimum de dépôt (FCFA)',
            ],
            [
                'key' => 'min_withdrawal_amount',
                'value' => '100',
                'type' => 'integer',
                'group' => 'system',
                'description' => 'Montant minimum de retrait (FCFA)',
            ],
        ];

        foreach ($settings as $setting) {
            Setting::updateOrCreate(
                ['key' => $setting['key']],
                $setting
            );
        }

        $this->command->info('Settings seeded successfully!');
    }
}
