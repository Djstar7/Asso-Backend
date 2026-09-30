<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\DelivererSyncCode;
use App\Models\DelivererCodeSync;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class DelivererSyncController extends Controller
{
    /**
     * Verify and sync deliverer profile using sync code
     * User must be authenticated (via Sanctum)
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function syncProfile(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'sync_code' => 'required|string|size:14', // XXXX-XXXX-XXXX = 14 chars
            'device_token' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => __('delivery.sync.invalid_data'),
                'errors' => $validator->errors()
            ], 422);
        }

        // Get authenticated user
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => __('auth.unauthenticated')
            ], 401);
        }

        // Find the sync code (by code only, not user_id since it's not linked yet)
        $syncCode = DelivererSyncCode::with('company.deliveryZones.pricelist')
            ->where('sync_code', $request->sync_code)
            ->first();

        if (!$syncCode) {
            return response()->json([
                'success' => false,
                'message' => __('delivery.sync.code_invalid')
            ], 404);
        }

        // Check if code is expired
        if ($syncCode->isExpired()) {
            return response()->json([
                'success' => false,
                'message' => __('delivery.sync.code_expired'),
                'expired_at' => $syncCode->expires_at
            ], 400);
        }

        // Check if this user is banned from this code
        $bannedSync = DelivererCodeSync::where('sync_code_id', $syncCode->id)
            ->where('user_id', $user->id)
            ->where('is_banned', true)
            ->first();

        if ($bannedSync) {
            return response()->json([
                'success' => false,
                'message' => __('delivery.sync.banned'),
                'banned_at' => $bannedSync->banned_at,
                'ban_reason' => $bannedSync->ban_reason
            ], 403);
        }

        // Check if this user already has an active sync with this code
        $existingActiveSync = DelivererCodeSync::where('sync_code_id', $syncCode->id)
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->first();

        if ($existingActiveSync) {
            return response()->json([
                'success' => false,
                'message' => __('delivery.sync.already_synced'),
                // Code stable : le message suit la langue de l'utilisateur.
                'code' => 'already_synced',
                'synced_at' => $existingActiveSync->synced_at
            ], 400);
        }

        // Get the company
        $company = $syncCode->company;

        if (!$company) {
            return response()->json([
                'success' => false,
                'message' => __('delivery.sync.company_not_found')
            ], 404);
        }

        DB::beginTransaction();
        try {
            // Create sync record in deliverer_code_syncs table
            $codeSync = DelivererCodeSync::create([
                'sync_code_id' => $syncCode->id,
                'user_id' => $user->id,
                'company_id' => $company->id,
                'is_active' => true,
                'is_banned' => false,
                'synced_at' => now(),
            ]);

            // If this is the first sync for this company, link it to the user
            if (!$company->user_id) {
                $company->update([
                    'user_id' => $user->id,
                    'is_active' => true,
                ]);
                \Log::info("✅ Deliverer company '{$company->name}' (ID: {$company->id}) linked to user {$user->id}");
            }

            // Activate company if inactive
            if (!$company->is_active) {
                $company->update(['is_active' => true]);
                \Log::info("✅ Deliverer company '{$company->name}' (ID: {$company->id}) has been ACTIVATED");
            }

            // Ajoute le rôle livreur SANS effacer les autres : un vendeur qui
            // devient aussi coursier doit garder l'accès à sa boutique.
            // addRole() retombe sur la colonne `role` quand `roles` est vide et
            // conserve le rôle principal existant.
            $user->addRole('livreur');

            // Mark sync code as used (but don't prevent reuse)
            // We keep this for backward compatibility
            if (!$syncCode->is_used) {
                $syncCode->update([
                    'user_id' => $user->id,
                    'is_used' => true,
                    'used_at' => now(),
                ]);
            }

            // Add device token if provided
            if ($request->device_token) {
                $user->deviceTokens()->updateOrCreate(
                    ['token' => $request->device_token],
                    ['device_type' => $request->device_type ?? 'mobile']
                );
            }

            DB::commit();

            \Log::info("✅ User {$user->id} successfully synced with code {$syncCode->sync_code} (Sync ID: {$codeSync->id})");
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error("❌ Error syncing user {$user->id} with code {$syncCode->sync_code}: " . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => __('delivery.sync.error', ['error' => $e->getMessage()])
            ], 500);
        }

        // Reload user with company data
        $user->load(['delivererCompany.deliveryZones.pricelist']);

        return response()->json([
            'success' => true,
            'message' => __('delivery.sync.success', ['company' => $company->name]),
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'first_name' => $user->first_name,
                    'last_name' => $user->last_name,
                    'email' => $user->email,
                    'phone' => $user->phone,
                    'role' => $user->role,
                    'roles' => $user->roles,
                ],
                'company' => [
                    'id' => $company->id,
                    'name' => $company->name,
                    'phone' => $company->phone,
                    'email' => $company->email,
                    'description' => $company->description,
                    'logo' => $company->logo ? media_url($company->logo) : null,
                    'is_active' => $company->is_active,
                ],
                'delivery_zones' => $company->deliveryZones->map(function ($zone) {
                    return [
                        'id' => $zone->id,
                        'name' => $zone->name,
                        'zone_data' => $zone->zone_data,
                        'center_latitude' => $zone->center_latitude,
                        'center_longitude' => $zone->center_longitude,
                        'is_active' => $zone->is_active,
                        'pricelist' => $zone->pricelist ? [
                            'id' => $zone->pricelist->id,
                            'pricing_type' => $zone->pricelist->pricing_type,
                            'pricing_data' => $zone->pricelist->pricing_data,
                            'is_active' => $zone->pricelist->is_active,
                        ] : null,
                    ];
                }),
                'synced_at' => now(),
            ]
        ]);
    }

    /**
     * Verify if a sync code is valid
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function verifySyncCode(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'sync_code' => 'required|string|size:14',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => __('delivery.sync.invalid_data'),
                'errors' => $validator->errors()
            ], 422);
        }

        $syncCode = DelivererSyncCode::where('sync_code', $request->sync_code)->first();

        if (!$syncCode) {
            return response()->json([
                'success' => false,
                'message' => __('delivery.sync.code_not_found'),
                'is_valid' => false
            ], 404);
        }

        $isValid = $syncCode->isValid();

        return response()->json([
            'success' => true,
            'is_valid' => $isValid,
            'data' => [
                'is_used' => $syncCode->is_used,
                'is_expired' => $syncCode->isExpired(),
                'expires_at' => $syncCode->expires_at,
                'user_id' => $syncCode->user_id,
            ]
        ]);
    }

    /**
     * Unsync deliverer profile
     * Removes deliverer role and marks sync code as unused
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function unsyncProfile(Request $request)
    {
        // Get authenticated user
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => __('auth.unauthenticated')
            ], 401);
        }

        // Check if user has deliverer role
        $roles = $user->roles ?? [];
        if (!in_array('livreur', $roles)) {
            return response()->json([
                'success' => false,
                'message' => __('delivery.sync.not_active_deliverer')
            ], 400);
        }

        // Find the sync code used by this user
        $syncCode = DelivererSyncCode::where('user_id', $user->id)
            ->where('is_used', true)
            ->first();

        if ($syncCode) {
            // Mark sync code as unused
            $syncCode->update([
                'user_id' => null,
                'is_used' => false,
                'used_at' => null,
            ]);

            \Log::info("🔓 Sync code {$syncCode->sync_code} marked as unused (user {$user->id} unsynced)");
        }

        // Find and update deliverer company
        $company = $user->delivererCompany;
        if ($company) {
            $company->update([
                'user_id' => null,
                'is_active' => false,
            ]);

            \Log::info("🔒 Deliverer company '{$company->name}' (ID: {$company->id}) deactivated (user {$user->id} unsynced)");
        }

        // Remove 'livreur' role from user
        $roles = array_values(array_diff($roles, ['livreur']));

        // Set primary role to the first available role or 'client'
        $primaryRole = !empty($roles) ? $roles[0] : 'client';

        $user->update([
            'role' => $primaryRole,
            'roles' => $roles,
        ]);

        \Log::info("✅ User {$user->id} unsynced successfully. Role changed from 'livreur' to '{$primaryRole}'");

        return response()->json([
            'success' => true,
            'message' => __('delivery.sync.unsynced'),
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'role' => $user->role,
                    'roles' => $user->roles,
                ],
            ]
        ]);
    }

    /**
     * Regenerate sync code for a deliverer
     * (Admin function - requires authentication)
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function regenerateSyncCode(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:users,id',
            'send_via' => 'required|in:email,sms,whatsapp,all',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => __('delivery.sync.invalid_data'),
                'errors' => $validator->errors()
            ], 422);
        }

        $user = User::find($request->user_id);

        if (!$user || !$user->hasRole('livreur')) {
            return response()->json([
                'success' => false,
                'message' => __('delivery.sync.user_not_deliverer')
            ], 404);
        }

        // Generate new sync code
        $syncCode = DelivererSyncCode::generateSyncCode();
        $expiresAt = now()->addDays(30);

        $delivererSyncCode = DelivererSyncCode::create([
            'user_id' => $user->id,
            'sync_code' => $syncCode,
            'sent_via' => $request->send_via,
            'sent_at' => now(),
            'expires_at' => $expiresAt,
        ]);

        // TODO: Send sync code via email/SMS
        // $this->sendSyncCode($user, $syncCode, $request->send_via);

        return response()->json([
            'success' => true,
            'message' => __('delivery.sync.code_generated'),
            'data' => [
                'sync_code' => $syncCode,
                'expires_at' => $expiresAt,
                'sent_via' => $request->send_via,
            ]
        ]);
    }
}
