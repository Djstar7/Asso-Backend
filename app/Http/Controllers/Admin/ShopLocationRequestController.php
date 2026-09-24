<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ShopLocationRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ShopLocationRequestController extends Controller
{
    /**
     * Display a listing of location change requests
     */
    public function index(Request $request)
    {
        $query = ShopLocationRequest::with(['shop', 'vendor', 'reviewer']);

        // Filter by status
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // Filter by shop
        if ($request->filled('shop_id')) {
            $query->where('shop_id', $request->shop_id);
        }

        // Filter by vendor
        if ($request->filled('vendor_id')) {
            $query->where('vendor_id', $request->vendor_id);
        }

        $requests = $query->latest()->paginate(15)->withQueryString();

        return response()->json([
            'success' => true,
            'requests' => $requests->map(function ($request) {
                return [
                    'id' => $request->id,
                    'shop' => [
                        'id' => $request->shop->id,
                        'name' => $request->shop->name,
                        'current_latitude' => $request->shop->latitude,
                        'current_longitude' => $request->shop->longitude,
                        'current_address' => $request->shop->address,
                    ],
                    'vendor' => [
                        'id' => $request->vendor->id,
                        'name' => $request->vendor->first_name . ' ' . $request->vendor->last_name,
                        'phone' => $request->vendor->phone,
                    ],
                    'requested_latitude' => $request->latitude,
                    'requested_longitude' => $request->longitude,
                    'requested_address' => $request->address,
                    'reason' => $request->reason,
                    'status' => $request->status,
                    'rejection_reason' => $request->rejection_reason,
                    'reviewer' => $request->reviewer ? [
                        'id' => $request->reviewer->id,
                        'name' => $request->reviewer->first_name . ' ' . $request->reviewer->last_name,
                    ] : null,
                    'reviewed_at' => $request->reviewed_at?->toIso8601String(),
                    'created_at' => $request->created_at->toIso8601String(),
                ];
            }),
            'pagination' => [
                'current_page' => $requests->currentPage(),
                'last_page' => $requests->lastPage(),
                'per_page' => $requests->perPage(),
                'total' => $requests->total(),
            ],
            'counts' => [
                'pending' => ShopLocationRequest::pending()->count(),
                'approved' => ShopLocationRequest::approved()->count(),
                'rejected' => ShopLocationRequest::rejected()->count(),
            ],
        ]);
    }

    /**
     * Approve a location change request
     */
    public function approve(Request $request, ShopLocationRequest $locationRequest)
    {
        if (!$locationRequest->isPending()) {
            return response()->json([
                'success' => false,
                'message' => 'Cette demande a déjà été traitée',
            ], 422);
        }

        DB::beginTransaction();
        try {
            Log::info('[ADMIN-LOCATION-REQUEST] Approving location change request', [
                'request_id' => $locationRequest->id,
                'shop_id' => $locationRequest->shop_id,
                'admin_id' => auth()->id(),
                'new_latitude' => $locationRequest->latitude,
                'new_longitude' => $locationRequest->longitude,
            ]);

            // Update shop location
            $shop = $locationRequest->shop;
            $oldLatitude = $shop->latitude;
            $oldLongitude = $shop->longitude;

            $shop->update(array_filter([
                'latitude' => $locationRequest->latitude,
                'longitude' => $locationRequest->longitude,
                'address' => $locationRequest->address ?? $shop->address,
                // Ville et pays lus sur la carte par l'app (sinon inchangés).
                'city' => $locationRequest->city,
                'country' => $locationRequest->country,
            ], fn ($value) => $value !== null));

            // Update request status
            $locationRequest->update([
                'status' => 'approved',
                'reviewed_by' => auth()->id(),
                'reviewed_at' => now(),
            ]);

            DB::commit();

            Log::info('[ADMIN-LOCATION-REQUEST] Location change approved', [
                'request_id' => $locationRequest->id,
                'shop_id' => $shop->id,
                'old_location' => ['lat' => $oldLatitude, 'lng' => $oldLongitude],
                'new_location' => ['lat' => $shop->latitude, 'lng' => $shop->longitude],
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Demande de changement d\'emplacement approuvée avec succès',
                'request' => [
                    'id' => $locationRequest->id,
                    'status' => $locationRequest->status,
                    'reviewed_at' => $locationRequest->reviewed_at->toIso8601String(),
                ],
                'shop' => [
                    'id' => $shop->id,
                    'latitude' => $shop->latitude,
                    'longitude' => $shop->longitude,
                    'address' => $shop->address,
                ],
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('[ADMIN-LOCATION-REQUEST] Error approving request', [
                'request_id' => $locationRequest->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l\'approbation de la demande',
                'error' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Reject a location change request
     */
    public function reject(Request $request, ShopLocationRequest $locationRequest)
    {
        if (!$locationRequest->isPending()) {
            return response()->json([
                'success' => false,
                'message' => 'Cette demande a déjà été traitée',
            ], 422);
        }

        $validated = $request->validate([
            'rejection_reason' => 'required|string|max:500',
        ]);

        try {
            Log::info('[ADMIN-LOCATION-REQUEST] Rejecting location change request', [
                'request_id' => $locationRequest->id,
                'shop_id' => $locationRequest->shop_id,
                'admin_id' => auth()->id(),
                'reason' => $validated['rejection_reason'],
            ]);

            $locationRequest->update([
                'status' => 'rejected',
                'reviewed_by' => auth()->id(),
                'reviewed_at' => now(),
                'rejection_reason' => $validated['rejection_reason'],
            ]);

            Log::info('[ADMIN-LOCATION-REQUEST] Location change rejected', [
                'request_id' => $locationRequest->id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Demande de changement d\'emplacement rejetée',
                'request' => [
                    'id' => $locationRequest->id,
                    'status' => $locationRequest->status,
                    'rejection_reason' => $locationRequest->rejection_reason,
                    'reviewed_at' => $locationRequest->reviewed_at->toIso8601String(),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('[ADMIN-LOCATION-REQUEST] Error rejecting request', [
                'request_id' => $locationRequest->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors du rejet de la demande',
                'error' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Get details of a specific request
     */
    public function show(ShopLocationRequest $locationRequest)
    {
        $locationRequest->load(['shop', 'vendor', 'reviewer']);

        return response()->json([
            'success' => true,
            'request' => [
                'id' => $locationRequest->id,
                'shop' => [
                    'id' => $locationRequest->shop->id,
                    'name' => $locationRequest->shop->name,
                    'current_latitude' => $locationRequest->shop->latitude,
                    'current_longitude' => $locationRequest->shop->longitude,
                    'current_address' => $locationRequest->shop->address,
                ],
                'vendor' => [
                    'id' => $locationRequest->vendor->id,
                    'name' => $locationRequest->vendor->first_name . ' ' . $locationRequest->vendor->last_name,
                    'phone' => $locationRequest->vendor->phone,
                    'email' => $locationRequest->vendor->email,
                ],
                'requested_latitude' => $locationRequest->latitude,
                'requested_longitude' => $locationRequest->longitude,
                'requested_address' => $locationRequest->address,
                'reason' => $locationRequest->reason,
                'status' => $locationRequest->status,
                'rejection_reason' => $locationRequest->rejection_reason,
                'reviewer' => $locationRequest->reviewer ? [
                    'id' => $locationRequest->reviewer->id,
                    'name' => $locationRequest->reviewer->first_name . ' ' . $locationRequest->reviewer->last_name,
                ] : null,
                'reviewed_at' => $locationRequest->reviewed_at?->toIso8601String(),
                'created_at' => $locationRequest->created_at->toIso8601String(),
            ],
        ]);
    }
}
