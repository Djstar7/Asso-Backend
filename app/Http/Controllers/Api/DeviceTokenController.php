<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DeviceToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class DeviceTokenController extends Controller
{
    /**
     * Enregistrer ou mettre à jour un token FCM pour l'utilisateur authentifié
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
        $attributes = [
            'user_id' => $user->id,
            'access_token_id' => $user->currentAccessToken() instanceof \Laravel\Sanctum\PersonalAccessToken ? $user->currentAccessToken()->getKey() : null,
            'platform' => $request->platform,
            'device_name' => $request->device_name,
            'device_model' => $request->device_model,
            'is_active' => true,
            'last_used_at' => now(),
        ];

        $deviceToken = DeviceToken::updateOrCreate(['token' => $request->token], $attributes);
        $existed = ! $deviceToken->wasRecentlyCreated;

        return response()->json([
            'success' => true,
            'message' => $existed
                ? __('devices.token_updated')
                : __('devices.token_registered'),
            'data' => $deviceToken,
        ], $existed ? 200 : 201);
    }

    /**
     * Obtenir tous les tokens de l'utilisateur authentifié
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
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        $deviceToken = DeviceToken::where('user_id', $user->id)
            ->where('id', $id)
            ->first();

        if (! $deviceToken) {
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
     */
    public function deactivate(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        $deviceToken = DeviceToken::where('user_id', $user->id)
            ->where('id', $id)
            ->first();

        if (! $deviceToken) {
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
     */
    public function activate(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        $deviceToken = DeviceToken::where('user_id', $user->id)
            ->where('id', $id)
            ->first();

        if (! $deviceToken) {
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
