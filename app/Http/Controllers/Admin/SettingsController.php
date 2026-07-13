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

        return view('admin.settings.payments', compact('paymentSettings'));
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
                // KPay
                'kpay_enabled' => 'nullable|boolean',
                'kpay_mode' => 'nullable|in:sandbox,live',
                'kpay_app_key' => 'nullable|string',
                'kpay_secret_key' => 'nullable|string',
                'kpay_callback_url' => 'nullable|url',
                // KPay Advanced Settings
                'kpay_base_url' => 'nullable|url',
                'kpay_timeout_init' => 'nullable|integer|min:1|max:120',
                'kpay_timeout_verify' => 'nullable|integer|min:1|max:120',
                'kpay_timeout_token' => 'nullable|integer|min:1|max:120',
                'kpay_token_cache_duration' => 'nullable|integer|min:60|max:3600',
                'kpay_retry_attempts' => 'nullable|integer|min:1|max:10',
                'kpay_retry_delay' => 'nullable|string',
            ]);

            foreach ($validated as $key => $value) {
                // Déterminer le type
                $type = 'string';
                if (str_ends_with($key, '_enabled') || str_ends_with($key, '_commission')) {
                    $type = 'boolean';
                } elseif (in_array($key, [
                    'fedapay_timeout',
                    'kpay_timeout_init',
                    'kpay_timeout_verify',
                    'kpay_timeout_token',
                    'kpay_token_cache_duration',
                    'kpay_retry_attempts'
                ])) {
                    $type = 'integer';
                }

                Setting::set($key, $value ?? '', $type, 'payment');
            }

            // Also save to ServiceConfiguration if using that model
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
        // KPay Configuration
        if (isset($validated['kpay_app_key']) || isset($validated['kpay_secret_key'])) {
            $kpayConfig = [
                'app_key' => $validated['kpay_app_key'] ?? '',
                'secret_key' => $validated['kpay_secret_key'] ?? '',
                'callback_url' => $validated['kpay_callback_url'] ?? '',
                'mode' => $validated['kpay_mode'] ?? 'sandbox',
                'base_url' => $validated['kpay_base_url'] ?? 'https://api-v2.kpay.com',
                'timeout_init' => $validated['kpay_timeout_init'] ?? 30,
                'timeout_verify' => $validated['kpay_timeout_verify'] ?? 30,
                'timeout_token' => $validated['kpay_timeout_token'] ?? 30,
                'token_cache_duration' => $validated['kpay_token_cache_duration'] ?? 3000,
                'retry_attempts' => $validated['kpay_retry_attempts'] ?? 5,
                'retry_delay' => $validated['kpay_retry_delay'] ?? '0.5',
            ];

            ServiceConfiguration::setConfig(
                ServiceConfiguration::SERVICE_KPAY,
                $kpayConfig,
                isset($validated['kpay_enabled']) && $validated['kpay_enabled'] == true,
                'Configuration KPay pour les paiements mobiles'
            );
        }
    }
}
