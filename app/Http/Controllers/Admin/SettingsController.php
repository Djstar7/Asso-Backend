<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CommissionRange;
use App\Models\ServiceConfiguration;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class SettingsController extends Controller
{
    /**
     * Afficher la page principale des paramètres.
     *
     * @return \Illuminate\View\View
     */
    public function index()
    {
        $generalSettings = Setting::where('group', 'general')->get()->keyBy('key');
        $systemSettings = Setting::where('group', 'system')->get()->keyBy('key');
        $commissionRanges = CommissionRange::orderBy('min_amount')->get();

        return view('admin.settings.index', compact('generalSettings', 'systemSettings', 'commissionRanges'));
    }

    /**
     * Mettre à jour les paramètres généraux.
     *
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function update(Request $request)
    {
        try {
            // Déterminer les règles selon les champs envoyés (onglet Général ou Système)
            $rules = [];

            // Onglet Général
            if ($request->has('app_name')) {
                $rules = array_merge($rules, [
                    'app_name' => 'required|string|max:255',
                    'app_slogan' => 'nullable|string|max:255',
                    'app_description' => 'nullable|string',
                    'contact_email' => 'required|email',
                    'contact_phone' => 'nullable|string|max:20',
                    'contact_address' => 'nullable|string|max:255',
                ]);
            }

            // Onglet Système
            if ($request->has('timezone')) {
                $rules = array_merge($rules, [
                    'timezone' => 'required|string',
                    'default_language' => 'required|string',
                    'currency' => 'required|string|max:10',
                    'currency_symbol' => 'required|string|max:10',
                    'min_deposit_amount' => 'required|integer|min:1',
                    'min_withdrawal_amount' => 'required|integer|min:1',
                ]);
            }

            $validated = $request->validate($rules);

            // Gérer l'upload du logo
            if ($request->hasFile('app_logo')) {
                $validated['app_logo'] = $request->file('app_logo')->store('settings', 'public');
                Setting::set('app_logo', $validated['app_logo'], 'file', 'general', 'Logo de l\'application');
            }

            // Mettre à jour tous les paramètres
            foreach ($validated as $key => $value) {
                if ($key === 'app_logo') {
                    continue; // Déjà géré ci-dessus
                }

                $type = in_array($key, ['app_description']) ? 'text' : 'string';
                if (in_array($key, ['min_deposit_amount', 'min_withdrawal_amount'])) {
                    $type = 'integer';
                }
                $group = in_array($key, ['timezone', 'default_language', 'currency', 'currency_symbol', 'min_deposit_amount', 'min_withdrawal_amount']) ? 'system' : 'general';

                Setting::set($key, $value, $type, $group);
            }

            return redirect()->route('admin.settings.index')
                ->with('success', 'Paramètres mis à jour avec succès');
        } catch (\Exception $e) {
            return redirect()->back()
                ->with('error', 'Erreur lors de la mise à jour des paramètres: ' . $e->getMessage())
                ->withInput();
        }
    }

    /**
     * Afficher la page de gestion de la maintenance.
     *
     * @return \Illuminate\View\View
     */
    public function maintenance()
    {
        $maintenanceSettings = Setting::where('group', 'maintenance')->get()->keyBy('key');

        return view('admin.settings.maintenance', compact('maintenanceSettings'));
    }

    /**
     * Mettre à jour les paramètres de maintenance.
     *
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function updateMaintenance(Request $request)
    {
        try {
            $validated = $request->validate([
                'maintenance_mode' => 'required|boolean',
                'maintenance_message' => 'nullable|string',
                'maintenance_end_time' => 'nullable|string',
            ]);

            Setting::set('maintenance_mode', $validated['maintenance_mode'], 'boolean', 'maintenance', 'Mode maintenance activé');
            Setting::set('maintenance_message', $validated['maintenance_message'] ?? '', 'text', 'maintenance', 'Message de maintenance');
            Setting::set('maintenance_end_time', $validated['maintenance_end_time'] ?? '', 'string', 'maintenance', 'Heure de fin estimée');

            return redirect()->route('admin.settings.maintenance')
                ->with('success', 'Paramètres de maintenance mis à jour avec succès');
        } catch (\Exception $e) {
            return redirect()->back()
                ->with('error', 'Erreur lors de la mise à jour: ' . $e->getMessage())
                ->withInput();
        }
    }

    /**
     * Afficher la page de configuration des paiements.
     *
     * @return \Illuminate\View\View
     */
    public function payments()
    {
        $paymentSettings = Setting::where('group', 'payment')->get()->keyBy('key');

        // KPay : source de vérité = service_configurations (lue par KPayService)
        $kpayService = ServiceConfiguration::where('service_name', ServiceConfiguration::SERVICE_KPAY)->first();
        $kpayConfig = $kpayService->configuration ?? [];
        $kpayEnabled = (bool) ($kpayService->is_active ?? false);

        // Conversion de devises (exchangerate-api.com)
        $exchangeRateConfig = ServiceConfiguration::where('service_name', 'exchange_rate')->first();
        $exchangeRateApiKey = $exchangeRateConfig->configuration['api_key'] ?? '';

        // Stripe : clés lues par StripeService (service_configurations, service 'stripe')
        $stripeService = ServiceConfiguration::where('service_name', ServiceConfiguration::SERVICE_STRIPE)->first();
        $stripeConfig = $stripeService->configuration ?? [];

        return view('admin.settings.payments', compact('paymentSettings', 'kpayConfig', 'kpayEnabled', 'exchangeRateApiKey', 'stripeConfig'));
    }

    /**
     * Diagnostic de la chaîne de virement IBAN (AJAX) — équivalent web de la
     * commande `stripe:doctor`.
     *
     * Contrôle ce qui a réellement empêché des virements d'aboutir : clés, fonds de
     * la plateforme (toutes devises), endpoints webhook joignables depuis Internet,
     * et comptes vendeurs validés chez nous mais refusés par Stripe.
     */
    public function diagnoseStripe()
    {
        $stripe = app(\App\Services\StripeService::class);

        if (!$stripe->isConfigured()) {
            return response()->json([
                'success' => false,
                'message' => 'Clés API Stripe manquantes.',
            ]);
        }

        $connection = $stripe->testConnection();
        if (!($connection['success'] ?? false)) {
            return response()->json(['success' => false, 'message' => $connection['message'] ?? 'Connexion Stripe impossible.']);
        }

        $checks = [];

        $checks[] = ['ok' => true, 'label' => $connection['message']];

        // Fonds : la devise n'est plus bloquante (conversion au transfert), seule
        // la capacité totale compte.
        $balances = $stripe->platformBalanceCurrencies();
        $capacity = $stripe->platformPayoutCapacity('EUR');
        $checks[] = [
            'ok' => $capacity > 0,
            'label' => $capacity > 0
                ? sprintf('Capacité de virement : %.2f EUR (soldes : %s).', $capacity, $this->formatBalances($balances))
                : 'Aucun fonds disponible sur le compte Stripe : les virements seront refusés.',
        ];

        // Webhooks : un endpoint vers une adresse locale ne recevra jamais rien.
        $endpoints = $stripe->listWebhookEndpoints();
        $unreachable = array_filter($endpoints, fn ($e) => !$stripe->isPubliclyReachableUrl($e['url']));
        $hasConnect = !empty(array_filter($endpoints, fn ($e) => $e['connect']));

        $checks[] = [
            'ok' => !empty($endpoints) && $hasConnect && empty($unreachable),
            'label' => match (true) {
                empty($endpoints) => 'Aucun endpoint webhook déclaré : les virements resteront « en cours » indéfiniment.',
                !empty($unreachable) => count($unreachable) . ' endpoint(s) injoignable(s) depuis Internet ('
                    . implode(', ', array_map(fn ($e) => $e['url'], array_slice($unreachable, 0, 2)))
                    . ') : aucun événement ne sera livré.',
                !$hasConnect => 'Aucun endpoint Connect (payout.paid / payout.failed) : les virements ne seront jamais marqués terminés.',
                default => count($endpoints) . ' endpoint(s) webhook opérationnel(s).',
            },
        ];

        $checks[] = [
            'ok' => !empty($stripe->webhookSecretConnect()),
            'label' => !empty($stripe->webhookSecretConnect())
                ? 'Secret de signature Connect configuré.'
                : 'Secret de signature Connect absent : les événements payout.* seront rejetés.',
        ];

        // Comptes vendeurs validés chez nous mais refusés par Stripe.
        $blocked = [];
        foreach (\App\Models\User::whereNotNull('stripe_account_id')
            ->where('stripe_account_status', 'approved')->limit(25)->get() as $vendor) {
            $state = $stripe->accountState($vendor->stripe_account_id);
            if ($state['exists'] && !$state['ready']) {
                $blocked[] = trim(($vendor->first_name ?? '') . ' ' . ($vendor->last_name ?? '')) . " (#{$vendor->id})";
            }
        }

        $checks[] = [
            'ok' => empty($blocked),
            'label' => empty($blocked)
                ? 'Tous les comptes vendeurs validés sont actifs chez Stripe.'
                : count($blocked) . ' compte(s) validé(s) chez nous mais NON activé(s) par Stripe : '
                    . implode(', ', array_slice($blocked, 0, 5)) . '.',
        ];

        return response()->json([
            'success' => collect($checks)->every(fn ($c) => $c['ok']),
            'checks' => $checks,
        ]);
    }

    /** « CAD 55.93, EUR 10.00 » */
    private function formatBalances(array $balances): string
    {
        if (empty($balances)) {
            return 'aucun';
        }

        return implode(', ', array_map(
            fn ($code, $amount) => sprintf('%s %.2f', $code, $amount),
            array_keys($balances),
            $balances,
        ));
    }

    /**
     * Tester la connexion à l'API KPay (AJAX).
     */
    public function testKpay()
    {
        $result = (new \App\Services\KPayService())->testConnection();
        return response()->json($result);
    }

    /**
     * Mettre à jour les paramètres de paiement.
     *
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function updatePayments(Request $request)
    {
        try {
            $validated = $request->validate([
                // PayPal
                'paypal_enabled' => 'nullable|boolean',
                'paypal_mode' => 'nullable|in:sandbox,live',
                'paypal_client_id' => 'nullable|string',
                'paypal_client_secret' => 'nullable|string',
                'paypal_webhook_id' => 'nullable|string',
                'paypal_currency' => 'nullable|string|in:USD,EUR,XOF',
                // Fedapay
                'fedapay_enabled' => 'nullable|boolean',
                'fedapay_mode' => 'nullable|in:sandbox,live',
                'fedapay_public_key' => 'nullable|string',
                'fedapay_secret_key' => 'nullable|string',
                'fedapay_webhook_secret' => 'nullable|string',
                'fedapay_currency' => 'nullable|string|in:XOF,XAF,NGN',
                'fedapay_callback_url' => 'nullable|url',
                'fedapay_timeout' => 'nullable|integer|min:60|max:600',
                'fedapay_auto_commission' => 'nullable|boolean',
                // KPay (API v1 — en-têtes X-API-Key / X-Secret-Key)
                'kpay_enabled' => 'nullable|boolean',
                'kpay_mode' => 'nullable|in:sandbox,live',
                'kpay_base_url' => 'nullable|url',
                'kpay_api_key' => 'nullable|string',
                'kpay_secret_key' => 'nullable|string',
                'kpay_webhook_secret' => 'nullable|string',
                'kpay_base_currency' => 'nullable|string|size:3',
                // Stripe (carte bancaire — encaissement inbound)
                'stripe_enabled' => 'nullable|boolean',
                'stripe_currency' => 'nullable|string|size:3',
                'stripe_mode' => 'nullable|in:test,live',
                'stripe_publishable_key' => 'nullable|string',
                'stripe_secret_key' => 'nullable|string',
                'stripe_webhook_secret' => 'nullable|string',
                // Événements Connect (payout.*, account.updated) : endpoint Stripe
                // distinct, donc secret de signature distinct.
                'stripe_webhook_secret_connect' => 'nullable|string',
                // Virements bancaires vendeurs (Stripe Connect — payout IBAN)
                'min_stripe_withdrawal_amount' => 'nullable|numeric|min:0',
                'stripe_fx_buffer_percent' => 'nullable|numeric|min:0|max:20',
                'stripe_business_url' => 'nullable|url',
                'stripe_business_mcc' => 'nullable|string|max:10',
                // Minimums d'encaissement par moyen (devise pivot XAF) — grisage mobile
                'pay_min_kpay' => 'nullable|numeric|min:0',
                'pay_min_paypal' => 'nullable|numeric|min:0',
                'pay_min_stripe' => 'nullable|numeric|min:0',
                // Conversion de devises (exchangerate-api.com)
                'exchange_rate_api_key' => 'nullable|string',
            ]);

            foreach ($validated as $key => $value) {
                // KPay, exchange-rate et les CLÉS Stripe sont stockés dans
                // service_configurations (source de vérité), pas dans la table settings.
                // (stripe_enabled / stripe_currency / pay_min_stripe restent en settings.)
                if (str_starts_with($key, 'kpay_') || str_starts_with($key, 'exchange_rate_')
                    || in_array($key, [
                        'stripe_mode', 'stripe_publishable_key', 'stripe_secret_key',
                        'stripe_webhook_secret', 'stripe_webhook_secret_connect',
                    ])) {
                    continue;
                }

                // Déterminer le type
                $type = 'string';
                if (str_ends_with($key, '_enabled') || str_ends_with($key, '_commission')) {
                    $type = 'boolean';
                } elseif (in_array($key, ['fedapay_timeout'])) {
                    $type = 'integer';
                }

                Setting::set($key, $value ?? '', $type, 'payment');
            }

            // KPay → service_configurations
            $this->updateServiceConfiguration($validated);

            return redirect()->route('admin.settings.payments')
                ->with('success', 'Paramètres de paiement mis à jour avec succès');
        } catch (\Exception $e) {
            return redirect()->back()
                ->with('error', 'Erreur lors de la mise à jour: ' . $e->getMessage())
                ->withInput();
        }
    }

    /**
     * Afficher la page de configuration des services.
     *
     * @return \Illuminate\View\View
     */
    public function services()
    {
        $smsSettings = Setting::where('group', 'sms')->get()->keyBy('key');
        $whatsappSettings = Setting::where('group', 'whatsapp')->get()->keyBy('key');

        return view('admin.settings.services', compact('smsSettings', 'whatsappSettings'));
    }

    /**
     * Mettre à jour les paramètres des services.
     *
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function updateServices(Request $request)
    {
        try {
            $validated = $request->validate([
                // OTP Default Service
                'otp_default_service' => 'nullable|string|in:auto,whatsapp,sms',
                // Nexaah SMS (Nexah API)
                'nexaah_enabled' => 'nullable|boolean',
                'nexaah_base_url' => 'nullable|url',
                'nexaah_send_endpoint' => 'nullable|string',
                'nexaah_credits_endpoint' => 'nullable|string',
                'nexaah_user' => 'nullable|string',
                'nexaah_password' => 'nullable|string',
                'nexaah_sender_id' => 'nullable|string|max:11',
                // WhatsApp (only 5 required fields)
                'whatsapp_enabled' => 'nullable|boolean',
                'whatsapp_api_token' => 'nullable|string',
                'whatsapp_phone_number_id' => 'nullable|string',
                'whatsapp_api_version' => 'nullable|string',
                'whatsapp_template_language' => 'nullable|string',
                'whatsapp_template_name' => 'nullable|string',
            ]);

            foreach ($validated as $key => $value) {
                // Déterminer le type
                $type = 'string';
                if (str_ends_with($key, '_enabled')) {
                    $type = 'boolean';
                }

                // Déterminer le groupe
                $group = 'sms'; // default
                if (str_starts_with($key, 'whatsapp')) {
                    $group = 'whatsapp';
                } elseif (str_starts_with($key, 'nexaah')) {
                    $group = 'sms';
                } elseif ($key === 'otp_default_service') {
                    $group = 'sms'; // Store in sms group for convenience
                }

                Setting::set($key, $value ?? '', $type, $group);
            }

            return redirect()->route('admin.settings.services')
                ->with('success', 'Paramètres des services mis à jour avec succès');
        } catch (\Exception $e) {
            return redirect()->back()
                ->with('error', 'Erreur lors de la mise à jour: ' . $e->getMessage())
                ->withInput();
        }
    }

    /**
     * Enregistrer les plages de commissions.
     */
    public function updateCommissions(Request $request)
    {
        try {
            $validated = $request->validate([
                'ranges' => 'required|array|min:1',
                'ranges.*.min_amount' => 'required|numeric|min:0',
                'ranges.*.max_amount' => 'required|numeric|gt:ranges.*.min_amount',
                'ranges.*.percentage' => 'required|numeric|min:0|max:100',
                'ranges.*.is_active' => 'nullable|boolean',
            ]);

            // Supprimer les anciennes plages et recréer
            CommissionRange::truncate();

            foreach ($validated['ranges'] as $range) {
                CommissionRange::create([
                    'min_amount' => $range['min_amount'],
                    'max_amount' => $range['max_amount'],
                    'percentage' => $range['percentage'],
                    'is_active' => isset($range['is_active']) ? true : false,
                ]);
            }

            return redirect()->route('admin.settings.index', ['tab' => 'commissions'])
                ->with('success', 'Plages de commissions mises à jour avec succès');
        } catch (\Exception $e) {
            return redirect()->back()
                ->with('error', 'Erreur lors de la mise à jour: ' . $e->getMessage())
                ->withInput();
        }
    }

    /**
     * Supprimer une plage de commission.
     */
    public function destroyCommission(CommissionRange $commission)
    {
        try {
            $commission->delete();

            return redirect()->route('admin.settings.index', ['tab' => 'commissions'])
                ->with('success', 'Plage de commission supprimée avec succès');
        } catch (\Exception $e) {
            return redirect()->back()
                ->with('error', 'Erreur lors de la suppression: ' . $e->getMessage());
        }
    }

    /**
     * Mettre à jour les configurations de service (KPay, etc).
     *
     * @param array $validated
     * @return void
     */
    private function updateServiceConfiguration(array $validated): void
    {
        // KPay : clés attendues par KPayService (api_key, secret_key, webhook_secret, base_url, mode).
        // On repart de la config existante ; les secrets laissés vides sont conservés.
        $existing = ServiceConfiguration::getConfig(ServiceConfiguration::SERVICE_KPAY) ?? [];

        $kpayConfig = array_merge([
            'base_url' => 'https://admin.kpay.site',
            'mode' => 'sandbox',
            'api_key' => '',
            'secret_key' => '',
            'webhook_secret' => '',
        ], $existing);

        if (!empty($validated['kpay_base_url'])) {
            $kpayConfig['base_url'] = $validated['kpay_base_url'];
        }
        if (!empty($validated['kpay_mode'])) {
            $kpayConfig['mode'] = $validated['kpay_mode'];
        }
        // Ne pas écraser une clé existante par une valeur vide (laisser vide = conserver)
        if (!empty($validated['kpay_api_key'])) {
            $kpayConfig['api_key'] = $validated['kpay_api_key'];
        }
        if (!empty($validated['kpay_secret_key'])) {
            $kpayConfig['secret_key'] = $validated['kpay_secret_key'];
        }
        if (!empty($validated['kpay_webhook_secret'])) {
            $kpayConfig['webhook_secret'] = $validated['kpay_webhook_secret'];
        }
        if (!empty($validated['kpay_base_currency'])) {
            $kpayConfig['base_currency'] = strtoupper($validated['kpay_base_currency']);
        }
        if (empty($kpayConfig['base_currency'])) {
            $kpayConfig['base_currency'] = 'XAF';
        }

        ServiceConfiguration::setConfig(
            ServiceConfiguration::SERVICE_KPAY,
            $kpayConfig,
            isset($validated['kpay_enabled']) && $validated['kpay_enabled'],
            'KPay - Paiements et retraits Mobile Money'
        );

        // Clé exchangerate-api.com (conversion de devises) — conservée si laissée vide.
        if (!empty($validated['exchange_rate_api_key'])) {
            $erExisting = ServiceConfiguration::getConfig('exchange_rate') ?? [];
            ServiceConfiguration::setConfig(
                'exchange_rate',
                array_merge($erExisting, ['api_key' => $validated['exchange_rate_api_key']]),
                true,
                'Conversion de devises (exchangerate-api.com)'
            );
        }

        // Stripe : clés lues par StripeService (secret_key, publishable_key, webhook_secret, mode).
        // Secrets laissés vides = conservés (on ne remplace jamais par une valeur vide).
        $stripeExisting = ServiceConfiguration::getConfig(ServiceConfiguration::SERVICE_STRIPE) ?? [];
        $stripeConfig = array_merge([
            'mode' => 'test',
            'publishable_key' => '',
            'secret_key' => '',
            'webhook_secret' => '',
            'webhook_secret_connect' => '',
        ], $stripeExisting);

        if (!empty($validated['stripe_mode'])) {
            $stripeConfig['mode'] = $validated['stripe_mode'];
        }
        if (!empty($validated['stripe_publishable_key'])) {
            $stripeConfig['publishable_key'] = $validated['stripe_publishable_key'];
        }
        if (!empty($validated['stripe_secret_key'])) {
            $stripeConfig['secret_key'] = $validated['stripe_secret_key'];
        }
        if (!empty($validated['stripe_webhook_secret'])) {
            $stripeConfig['webhook_secret'] = $validated['stripe_webhook_secret'];
        }
        if (!empty($validated['stripe_webhook_secret_connect'])) {
            $stripeConfig['webhook_secret_connect'] = $validated['stripe_webhook_secret_connect'];
        }

        ServiceConfiguration::setConfig(
            ServiceConfiguration::SERVICE_STRIPE,
            $stripeConfig,
            isset($validated['stripe_enabled']) && $validated['stripe_enabled'],
            'Stripe - Encaissement carte + payout IBAN (Connect)'
        );
    }
}
