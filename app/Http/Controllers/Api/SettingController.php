<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;

class SettingController extends Controller
{
    /**
     * Récupérer tous les paramètres publics.
     *
     * @return JsonResponse
     */
    public function index(): JsonResponse
    {
        try {
            // Groupes de paramètres publics
            $publicGroups = ['general', 'system'];

            $settings = [];

            foreach ($publicGroups as $group) {
                $groupSettings = Setting::getByGroup($group);

                // Filtrer les données sensibles
                $settings[$group] = $this->filterSensitiveData($groupSettings);
            }

            return response()->json([
                'success' => true,
                'data' => $settings,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => __('settings.fetch_error'),
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Récupérer les paramètres d'un groupe spécifique.
     *
     * @param string $group
     * @return JsonResponse
     */
    public function getByGroup(string $group): JsonResponse
    {
        try {
            // Vérifier que le groupe est public
            $publicGroups = ['general', 'system'];

            if (!in_array($group, $publicGroups)) {
                return response()->json([
                    'success' => false,
                    'message' => __('settings.group_access_denied'),
                ], 403);
            }

            $settings = Setting::getByGroup($group);
            $filteredSettings = $this->filterSensitiveData($settings);

            return response()->json([
                'success' => true,
                'data' => $filteredSettings,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => __('settings.fetch_error'),
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Récupérer un paramètre spécifique par sa clé.
     *
     * @param string $key
     * @return JsonResponse
     */
    public function show(string $key): JsonResponse
    {
        try {
            // Liste des clés publiques autorisées
            $publicKeys = [
                'app_name',
                'app_logo',
                'app_slogan',
                'app_description',
                'contact_email',
                'contact_phone',
                'contact_address',
                'timezone',
                'default_language',
                'currency',
                'currency_symbol',
                'min_deposit_amount',
                'min_withdrawal_amount',
            ];

            if (!in_array($key, $publicKeys)) {
                return response()->json([
                    'success' => false,
                    'message' => __('settings.access_denied'),
                ], 403);
            }

            $value = Setting::get($key);

            if ($value === null) {
                return response()->json([
                    'success' => false,
                    'message' => __('settings.not_found'),
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'key' => $key,
                    'value' => $value,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => __('settings.fetch_one_error'),
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Filtrer les données sensibles des paramètres.
     *
     * @param array $settings
     * @return array
     */
    private function filterSensitiveData(array $settings): array
    {
        $sensitiveKeys = [
            'paypal_client_id',
            'paypal_secret',
            'fedapay_public_key',
            'fedapay_secret_key',
            'nexaah_api_key',
            'whatsapp_api_token',
        ];

        return array_filter($settings, function ($key) use ($sensitiveKeys) {
            return !in_array($key, $sensitiveKeys);
        }, ARRAY_FILTER_USE_KEY);
    }
}
