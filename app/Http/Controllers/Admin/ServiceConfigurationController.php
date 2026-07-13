<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ServiceConfiguration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ServiceConfigurationController extends Controller
{
    /**
     * Afficher la liste des configurations de services.
     *
     * @return \Illuminate\View\View
     */
    public function index()
    {
        $whatsappConfig = ServiceConfiguration::getWhatsAppConfig();
        $nexahConfig = ServiceConfiguration::where('service_name', 'nexaah_sms')->first();
        $kpayConfig = ServiceConfiguration::where('service_name', 'kpay')->first();

        return view('admin.service-config.index', compact('whatsappConfig', 'nexahConfig', 'kpayConfig'));
    }

    /**
     * Mettre à jour la configuration d'un service.
     *
     * @param Request $request
     * @param string $serviceName
     * @return \Illuminate\Http\RedirectResponse
     */
    public function update(Request $request, string $serviceName)
    {
        try {
            $validated = $request->validate([
                'is_active' => 'required|boolean',
                'configuration' => 'required|array',
                'description' => 'nullable|string',
            ]);

            ServiceConfiguration::setConfig(
                $serviceName,
                $validated['configuration'],
                $validated['is_active'],
                $validated['description'] ?? null
            );

            return redirect()->back()
                ->with('success', 'Configuration du service mise à jour avec succès');
        } catch (\Exception $e) {
            return redirect()->back()
                ->with('error', 'Erreur lors de la mise à jour: ' . $e->getMessage())
                ->withInput();
        }
    }

    /**
     * Activer ou désactiver un service.
     *
     * @param string $serviceName
     * @return \Illuminate\Http\RedirectResponse
     */
    public function toggle(string $serviceName)
    {
        try {
            $service = ServiceConfiguration::where('service_name', $serviceName)->first();

            if (!$service) {
                return redirect()->back()
                    ->with('error', 'Service non trouvé');
            }

            $newStatus = !$service->is_active;
            ServiceConfiguration::toggleService($serviceName, $newStatus);

            $message = $newStatus ? 'Service activé' : 'Service désactivé';

            return redirect()->back()
                ->with('success', $message);
        } catch (\Exception $e) {
            return redirect()->back()
                ->with('error', 'Erreur lors du basculement du service: ' . $e->getMessage());
        }
    }

    /**
     * Tester la connexion à un service.
     *
     * @param string $serviceName
     * @return \Illuminate\Http\JsonResponse
     */
    public function test(string $serviceName)
    {
        try {
            $config = ServiceConfiguration::getConfig($serviceName);

            if (!$config) {
                return response()->json([
                    'success' => false,
                    'message' => 'Service non configuré ou inactif',
                ], 404);
            }

            // Logique de test selon le service
            $testResult = $this->performServiceTest($serviceName, $config);

            return response()->json([
                'success' => $testResult['success'],
                'message' => $testResult['message'],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors du test: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Effectuer un test de connexion au service.
     *
     * @param string $serviceName
     * @param array $config
     * @return array
     */
    private function performServiceTest(string $serviceName, array $config): array
    {
        try {
            switch ($serviceName) {
                case ServiceConfiguration::SERVICE_NEXAAH_SMS:
                    $nexahService = new \App\Services\NexaahService();
                    if (!$nexahService->isConfigured()) {
                        return [
                            'success' => false,
                            'message' => 'Service Nexaah SMS non configuré correctement',
                        ];
                    }
                    return $nexahService->testConnection();

                case ServiceConfiguration::SERVICE_KPAY:
                    $kpayService = new \App\Services\KPayService();
                    return $kpayService->testConnection();

                default:
                    return [
                        'success' => true,
                        'message' => 'Test de connexion non implémenté pour ce service',
                    ];
            }
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Erreur lors du test: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Update Nexah SMS configuration (API endpoint)
     */
    public function updateNexah(Request $request)
    {
        \Illuminate\Support\Facades\Log::info('Updating Nexah SMS configuration', $request->all());

        $validated = $request->validate([
            'base_url' => 'required|url',
            'send_endpoint' => 'required|string',
            'credits_endpoint' => 'nullable|string',
            'user' => 'required|string|min:3',
            'password' => 'required|string|min:3',
            'sender_id' => 'required|string|min:3|max:11',
            'is_active' => 'nullable|boolean',
        ]);

        try {
            $configuration = [
                'base_url' => $validated['base_url'],
                'send_endpoint' => $validated['send_endpoint'],
                'credits_endpoint' => $validated['credits_endpoint'] ?? '/account/1/balance',
                'user' => $validated['user'],
                'password' => $validated['password'],
                'sender_id' => $validated['sender_id'],
            ];

            $config = ServiceConfiguration::setConfig(
                ServiceConfiguration::SERVICE_NEXAAH_SMS,
                $configuration,
                $request->boolean('is_active', false),
                'Configuration pour l\'envoi de SMS via Nexah API'
            );

            \Illuminate\Support\Facades\Log::info('Nexah SMS configuration updated successfully', [
                'id' => $config->id,
                'is_active' => $config->is_active,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Configuration Nexah SMS mise à jour avec succès!',
                'config' => [
                    'is_active' => $config->is_active,
                    'configuration' => $config->configuration,
                ],
            ]);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Error updating Nexah SMS configuration', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la mise à jour: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Send test SMS via Nexah
     */
    public function sendTestNexah(Request $request)
    {
        \Illuminate\Support\Facades\Log::info('Sending test SMS via Nexah', ['phone' => $request->phone]);

        $validated = $request->validate([
            'phone' => 'required|string|min:9',
            'message' => 'required|string|max:160',
        ]);

        try {
            $nexahService = new \App\Services\NexaahService();

            if (!$nexahService->isConfigured()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Configuration Nexah invalide ou incomplète. Veuillez d\'abord configurer le service.'
                ], 400);
            }

            $result = $nexahService->sendSms(
                $validated['phone'],
                $validated['message']
            );

            \Illuminate\Support\Facades\Log::info('Test SMS send result', [
                'phone' => $validated['phone'],
                'result' => $result,
            ]);

            return response()->json($result, $result['success'] ? 200 : 400);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Error sending test SMS', [
                'phone' => $validated['phone'],
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Send test OTP via Nexah
     */
    public function sendTestOtp(Request $request)
    {
        \Illuminate\Support\Facades\Log::info('Sending test OTP via Nexah', ['phone' => $request->phone]);

        $validated = $request->validate([
            'phone' => 'required|string|min:9',
        ]);

        try {
            $nexahService = new \App\Services\NexaahService();

            if (!$nexahService->isConfigured()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Configuration Nexah invalide ou incomplète. Veuillez d\'abord configurer le service.'
                ], 400);
            }

            // Generate random OTP
            $otpCode = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);

            $result = $nexahService->sendOtp($validated['phone'], $otpCode);

            \Illuminate\Support\Facades\Log::info('Test OTP send result', [
                'phone' => $validated['phone'],
                'otp' => $otpCode,
                'result' => $result,
            ]);

            return response()->json([
                'success' => $result['success'],
                'message' => $result['message'],
                'otp_code' => $otpCode, // Return for testing
            ], $result['success'] ? 200 : 400);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Error sending test OTP', [
                'phone' => $validated['phone'],
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update Nexah SMS configuration (Web form)
     */
    public function updateNexahWeb(Request $request)
    {
        \Illuminate\Support\Facades\Log::info('Updating Nexah SMS configuration via web', $request->all());

        $validated = $request->validate([
            'base_url' => 'required|url',
            'send_endpoint' => 'required|string',
            'credits_endpoint' => 'nullable|string',
            'user' => 'required|string|min:3',
            'password' => 'required|string|min:3',
            'sender_id' => 'required|string|min:3|max:11',
            'is_active' => 'nullable',
        ]);

        try {
            $configuration = [
                'base_url' => $validated['base_url'],
                'send_endpoint' => $validated['send_endpoint'],
                'credits_endpoint' => $validated['credits_endpoint'] ?? '/smscredit',
                'user' => $validated['user'],
                'password' => $validated['password'],
                'sender_id' => $validated['sender_id'],
            ];

            $config = ServiceConfiguration::setConfig(
                ServiceConfiguration::SERVICE_NEXAAH_SMS,
                $configuration,
                $request->has('is_active'),
                'Configuration pour l\'envoi de SMS via Nexah API'
            );

            \Illuminate\Support\Facades\Log::info('Nexah SMS configuration updated successfully via web', [
                'id' => $config->id,
                'is_active' => $config->is_active,
            ]);

            return redirect()->back()->with('success', 'Configuration Nexah SMS mise à jour avec succès!');
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Error updating Nexah SMS configuration via web', [
                'error' => $e->getMessage(),
            ]);

            return redirect()->back()->with('error', 'Erreur lors de la mise à jour: ' . $e->getMessage())->withInput();
        }
    }

    /**
     * Update KPay configuration (Web form)
     */
    public function updateKpayWeb(Request $request)
    {
        \Illuminate\Support\Facades\Log::info('Updating KPay configuration via web', $request->all());

        $validated = $request->validate([
            'base_url' => 'required|url',
            'app_key' => 'required|string|min:5',
            'secret_key' => 'required|string|min:5',
            'callback_url' => 'required|url',
            'is_active' => 'nullable',
        ]);

        try {
            $configuration = [
                'base_url' => $validated['base_url'],
                'app_key' => $validated['app_key'],
                'secret_key' => $validated['secret_key'],
                'callback_url' => $validated['callback_url'],
            ];

            $config = ServiceConfiguration::setConfig(
                ServiceConfiguration::SERVICE_KPAY,
                $configuration,
                $request->has('is_active'),
                'Configuration pour les paiements via KPay'
            );

            \Illuminate\Support\Facades\Log::info('KPay configuration updated successfully via web', [
                'id' => $config->id,
                'is_active' => $config->is_active,
            ]);

            return redirect()->back()->with('success', 'Configuration KPay mise à jour avec succès!');
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Error updating KPay configuration via web', [
                'error' => $e->getMessage(),
            ]);

            return redirect()->back()->with('error', 'Erreur lors de la mise à jour: ' . $e->getMessage())->withInput();
        }
    }

    /**
     * Update WhatsApp configuration
     */
    public function updateWhatsApp(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'whatsapp_api_token' => 'required|string|min:10',
            'whatsapp_phone_number_id' => 'required|string|min:10',
            'whatsapp_api_version' => 'required|string',
            'whatsapp_template_name' => 'required|string|min:3',
            'whatsapp_language' => 'required|string|max:10',
        ]);

        if ($validator->fails()) {
            $errors = $validator->errors()->all();
            return redirect()->back()
                ->withErrors($validator)
                ->withInput()
                ->with('error', 'Erreurs de validation: ' . implode(' | ', $errors));
        }

        try {
            $config = ServiceConfiguration::updateOrCreate(
                ['service_type' => 'whatsapp'],
                [
                    'service_name' => 'whatsapp',
                    'whatsapp_api_token' => $request->whatsapp_api_token,
                    'whatsapp_phone_number_id' => $request->whatsapp_phone_number_id,
                    'whatsapp_api_version' => $request->whatsapp_api_version,
                    'whatsapp_template_name' => $request->whatsapp_template_name,
                    'whatsapp_language' => $request->whatsapp_language,
                    'is_active' => $request->has('is_active'),
                ]
            );

            // Clear cache
            ServiceConfiguration::clearCache('whatsapp');

            // Validate configuration
            $errors = $config->validateWhatsAppConfig();
            if (!empty($errors)) {
                return redirect()->back()
                    ->with('warning', 'Configuration sauvegardée avec des avertissements: ' . implode(', ', $errors));
            }

            return redirect()->back()
                ->with('success', 'Configuration WhatsApp mise à jour avec succès!');
        } catch (\Exception $e) {
            return redirect()->back()
                ->with('error', 'Erreur lors de la mise à jour: ' . $e->getMessage())
                ->withInput();
        }
    }

    /**
     * Test WhatsApp connection
     */
    public function testWhatsApp()
    {
        $config = ServiceConfiguration::getWhatsAppConfig();

        if (!$config || !$config->isConfigured()) {
            return response()->json([
                'success' => false,
                'message' => 'Configuration WhatsApp invalide ou incomplète'
            ], 400);
        }

        $whatsappService = new \App\Services\WhatsAppService();
        $result = $whatsappService->testConnection();

        return response()->json($result, $result['success'] ? 200 : 400);
    }

    /**
     * Send test WhatsApp message
     */
    public function sendTestWhatsApp(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'phone' => 'required|string',
            'otp' => 'required|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Données invalides: ' . $validator->errors()->first()
            ], 400);
        }

        $config = ServiceConfiguration::getWhatsAppConfig();

        if (!$config) {
            return response()->json([
                'success' => false,
                'message' => 'Aucune configuration WhatsApp trouvée. Veuillez d\'abord sauvegarder la configuration.'
            ], 400);
        }

        // Check what's missing in configuration
        $errors = $config->validateWhatsAppConfig();
        if (!empty($errors)) {
            return response()->json([
                'success' => false,
                'message' => 'Configuration incomplète. Champs manquants: ' . implode(', ', $errors),
                'errors' => $errors,
                'config_debug' => [
                    'has_token' => !empty($config->whatsapp_api_token),
                    'has_phone_id' => !empty($config->whatsapp_phone_number_id),
                    'has_template' => !empty($config->whatsapp_template_name),
                    'is_active' => $config->is_active
                ]
            ], 400);
        }

        try {
            $whatsappService = new \App\Services\WhatsAppService();
            $result = $whatsappService->sendOtp(
                $request->input('phone'),
                $request->input('otp')
            );

            return response()->json($result, $result['success'] ? 200 : 400);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Clear all service configuration cache
     */
    public function clearCache()
    {
        try {
            ServiceConfiguration::clearCache();

            return redirect()->back()
                ->with('success', 'Cache des configurations nettoyé avec succès!');
        } catch (\Exception $e) {
            return redirect()->back()
                ->with('error', 'Erreur lors du nettoyage du cache: ' . $e->getMessage());
        }
    }
}
