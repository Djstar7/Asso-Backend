<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DeviceToken;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;

class DeviceTokenController extends Controller
{
    /**
     * Enregistrer ou mettre à jour un token FCM pour l'utilisateur authentifié
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'token' => 'required|string|max:255',
            'platform' => 'required|in:android,ios,web,macos,windows',
            'device_name' => 'nullable|string|max:255',
            'device_model' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => __('common.validation_failed'),
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = $request->user();

        // Le token FCM est unique par APPAREIL, pas par utilisateur : si le même
        // téléphone sert à un autre compte (déconnexion / reconnexion), la ligne
        // est réattribuée au nouvel utilisateur au lieu de violer la contrainte
        // d'unicité sur `token`.
        $deviceToken = DeviceToken::where('token', $request->token)->first();
        $existed = $deviceToken !== null;

        $attributes = [
            'user_id' => $user->id,
            'platform' => $request->platform,
            'device_name' => $request->device_name,
            'device_model' => $request->device_model,
            'is_active' => true,
            'last_used_at' => now(),
        ];

        if ($existed) {
            $deviceToken->update($attributes);
        } else {
            $deviceToken = DeviceToken::create($attributes + ['token' => $request->token]);
        }

        return response()->json([
            'success' => true,
            'message' => $existed
                ? 'Device token updated successfully'
                : 'Device token registered successfully',
            'data' => $deviceToken,
        ], $existed ? 200 : 201);
    }

    /**
     * Obtenir tous les tokens de l'utilisateur authentifié
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $tokens = DeviceToken::where('user_id', $user->id)
            ->orderBy('last_used_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $tokens,
        ]);
    }

    /**
     * Supprimer un token spécifique
     *
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        $deviceToken = DeviceToken::where('user_id', $user->id)
            ->where('id', $id)
            ->first();

        if (!$deviceToken) {
            return response()->json([
                'success' => false,
                'message' => __('devices.token_not_found'),
            ], 404);
        }

        $deviceToken->delete();

        return response()->json([
            'success' => true,
            'message' => __('devices.token_deleted'),
        ]);
    }

    /**
     * Supprimer un token par sa valeur
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function deleteByToken(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'token' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => __('common.validation_failed'),
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = $request->user();

        $deleted = DeviceToken::where('user_id', $user->id)
            ->where('token', $request->token)
            ->delete();

        if ($deleted) {
            return response()->json([
                'success' => true,
                'message' => __('devices.token_deleted'),
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => __('devices.token_not_found'),
        ], 404);
    }

    /**
     * Désactiver un token
     *
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     */
    public function deactivate(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        $deviceToken = DeviceToken::where('user_id', $user->id)
            ->where('id', $id)
            ->first();

        if (!$deviceToken) {
            return response()->json([
                'success' => false,
                'message' => __('devices.token_not_found'),
            ], 404);
        }

        $deviceToken->deactivate();

        return response()->json([
            'success' => true,
            'message' => __('devices.token_deactivated'),
            'data' => $deviceToken->fresh(),
        ]);
    }

    /**
     * Activer un token
     *
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     */
    public function activate(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        $deviceToken = DeviceToken::where('user_id', $user->id)
            ->where('id', $id)
            ->first();

        if (!$deviceToken) {
            return response()->json([
                'success' => false,
                'message' => __('devices.token_not_found'),
            ], 404);
        }

        $deviceToken->activate();

        return response()->json([
            'success' => true,
            'message' => __('devices.token_activated'),
            'data' => $deviceToken->fresh(),
        ]);
    }
}
