<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Shop;
use App\Models\User;
use App\Models\ShopLocationRequest;
use App\Services\FCMService;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class ShopController extends Controller
{
    /**
     * Display a listing of shops
     */
    public function index(Request $request)
    {
        $query = Shop::with(['user', 'products', 'locationRequests' => function($q) {
            $q->where('status', 'pending')->latest();
        }]);

        // Search filter
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%")
                  ->orWhereHas('user', function($userQuery) use ($search) {
                      $userQuery->where('first_name', 'like', "%{$search}%")
                                ->orWhere('last_name', 'like', "%{$search}%");
                  });
            });
        }

        // Status filter
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // User filter
        if ($request->filled('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        $shops = $query->latest()->paginate(15)->withQueryString();
        $users = User::all();

        return view('admin.shops.index', compact('shops', 'users'));
    }

    /**
     * Show the form for creating a new shop
     */
    public function create()
    {
        $users = User::all();
        return view('admin.shops.create', compact('users'));
    }

    /**
     * Store a newly created shop
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'shop_link' => 'nullable|url',
            'address' => 'nullable|string',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'status' => 'required|in:active,inactive',
            'logo' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:2048',
            'categories' => 'nullable|array',
            'categories.*' => 'string',
        ]);

        $validated['slug'] = Str::slug($validated['name']) . '-' . time();

        // Handle logo upload
        if ($request->hasFile('logo')) {
            $logo = $request->file('logo');
            $logoName = time() . '_' . Str::slug($validated['name']) . '.' . $logo->getClientOriginalExtension();
            $logo->move(public_path('storage/shops'), $logoName);
            $validated['logo'] = 'storage/shops/' . $logoName;
        }

        Shop::create($validated);

        return redirect()->route('admin.shops.index')
            ->with('success', 'Boutique créée avec succès!');
    }

    /**
     * Display the specified shop
     */
    public function show(Shop $shop)
    {
        $shop->load(['user', 'products', 'locationRequests' => function($q) {
            $q->latest();
        }]);
        return view('admin.shops.show', compact('shop'));
    }

    /**
     * Show the form for editing the specified shop
     */
    public function edit(Shop $shop)
    {
        $shop->load('verifier', 'rejector', 'user', 'products');
        $users = User::all();
        return view('admin.shops.edit', compact('shop', 'users'));
    }

    /**
     * Update the specified shop
     */
    public function update(Request $request, Shop $shop)
    {
        Log::info('[ADMIN-SHOP-UPDATE] Starting shop update', [
            'shop_id' => $shop->id,
            'shop_name' => $shop->name,
            'admin_id' => auth()->id(),
            'request_data' => $request->except(['logo'])
        ]);

        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'shop_link' => 'nullable|url',
            'address' => 'nullable|string',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'status' => 'required|in:active,inactive',
            'verification_status' => 'required|in:pending,verified,rejected',
            'rejection_reason' => 'required_if:verification_status,rejected|nullable|string|max:500',
            'logo' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:2048',
            'categories' => 'nullable|array',
            'categories.*' => 'string',
        ]);

        Log::info('[ADMIN-SHOP-UPDATE] Validation passed', [
            'validated_fields' => array_keys($validated)
        ]);

        // Update slug only if name changed
        if ($shop->name !== $validated['name']) {
            $validated['slug'] = Str::slug($validated['name']) . '-' . time();
        }

        // Handle logo upload
        if ($request->hasFile('logo')) {
            // Delete old logo if exists
            if ($shop->logo && file_exists(public_path($shop->logo))) {
                unlink(public_path($shop->logo));
            }

            $logo = $request->file('logo');
            $logoName = time() . '_' . Str::slug($validated['name']) . '.' . $logo->getClientOriginalExtension();
            $logo->move(public_path('storage/shops'), $logoName);
            $validated['logo'] = 'storage/shops/' . $logoName;
        }

        // Handle verification status
        $previousStatus = $shop->verified_at ? 'verified' : ($shop->rejected_at ? 'rejected' : 'pending');

        Log::info('[ADMIN-SHOP-UPDATE] Handling verification status', [
            'previous_status' => $previousStatus,
            'new_status' => $validated['verification_status'],
            'rejection_reason' => $validated['rejection_reason'] ?? null
        ]);

        switch ($validated['verification_status']) {
            case 'verified':
                $validated['verified_at'] = now();
                $validated['verified_by'] = auth()->id();
                $validated['rejected_at'] = null;
                $validated['rejected_by'] = null;
                $validated['rejection_reason'] = null;

                Log::info('[ADMIN-SHOP-UPDATE] Shop verified', [
                    'shop_id' => $shop->id,
                    'verified_by' => auth()->id(),
                    'verified_at' => now()
                ]);
                break;

            case 'rejected':
                $validated['rejected_at'] = now();
                $validated['rejected_by'] = auth()->id();
                $validated['verified_at'] = null;
                $validated['verified_by'] = null;

                Log::warning('[ADMIN-SHOP-UPDATE] Shop rejected', [
                    'shop_id' => $shop->id,
                    'rejected_by' => auth()->id(),
                    'rejected_at' => now(),
                    'reason' => $validated['rejection_reason']
                ]);
                break;

            case 'pending':
            default:
                $validated['verified_at'] = null;
                $validated['verified_by'] = null;
                $validated['rejected_at'] = null;
                $validated['rejected_by'] = null;
                $validated['rejection_reason'] = null;

                Log::info('[ADMIN-SHOP-UPDATE] Shop status set to pending', [
                    'shop_id' => $shop->id
                ]);
                break;
        }

        // Remove verification_status from validated array as it's not a database field
        unset($validated['verification_status']);

        try {
            $shop->update($validated);

            Log::info('[ADMIN-SHOP-UPDATE] Shop updated successfully', [
                'shop_id' => $shop->id,
                'updated_fields' => array_keys($validated)
            ]);

            return redirect()->route('admin.shops.index')
                ->with('success', 'Boutique mise à jour avec succès!');
        } catch (\Exception $e) {
            Log::error('[ADMIN-SHOP-UPDATE] Failed to update shop', [
                'shop_id' => $shop->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return redirect()->back()
                ->withInput()
                ->with('error', 'Erreur lors de la mise à jour de la boutique: ' . $e->getMessage());
        }
    }

    /**
     * Remove the specified shop
     */
    public function destroy(Shop $shop)
    {
        // Delete logo if exists
        if ($shop->logo && file_exists(public_path($shop->logo))) {
            unlink(public_path($shop->logo));
        }

        $shop->delete();

        return redirect()->route('admin.shops.index')
            ->with('success', 'Boutique supprimée avec succès!');
    }

    /**
     * Approve a location change request
     */
    public function approveLocationRequest(Shop $shop, ShopLocationRequest $request)
    {
        try {
            Log::info('[ADMIN-LOCATION-REQUEST-APPROVE] Starting approval', [
                'shop_id' => $shop->id,
                'request_id' => $request->id,
                'old_lat' => $shop->latitude,
                'old_lng' => $shop->longitude,
                'new_lat' => $request->latitude,
                'new_lng' => $request->longitude,
            ]);

            // Update shop location
            $shop->update([
                'latitude' => $request->latitude,
                'longitude' => $request->longitude,
            ]);

            // Update request status
            $request->update([
                'status' => 'approved',
                'reviewed_at' => now(),
                'reviewed_by' => auth()->id(),
            ]);

            // Send FCM notification to vendor
            $fcmService = app(FCMService::class);
            $vendor = $shop->user;

            if ($vendor) {
                $fcmService->sendToUser(
                    $vendor,  // Pass the User object, not the ID
                    'Changement de localisation approuvé',
                    "Votre demande de changement de localisation pour {$shop->name} a été approuvée par l'administrateur.",
                    [
                        'type' => 'location_request_approved',
                        'shop_id' => $shop->id,
                        'request_id' => $request->id,
                        'new_latitude' => $request->latitude,
                        'new_longitude' => $request->longitude,
                    ]
                );

                Log::info('[ADMIN-LOCATION-REQUEST-APPROVE] FCM notification sent', [
                    'vendor_id' => $vendor->id,
                    'shop_id' => $shop->id,
                ]);
            }

            Log::info('[ADMIN-LOCATION-REQUEST-APPROVE] Approval successful');

            return redirect()->route('admin.shops.show', $shop)
                ->with('success', 'Demande de changement de localisation approuvée avec succès! Le vendeur a été notifié.');
        } catch (\Exception $e) {
            Log::error('[ADMIN-LOCATION-REQUEST-APPROVE] Error approving location request', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return redirect()->back()
                ->with('error', 'Erreur lors de l\'approbation de la demande: ' . $e->getMessage());
        }
    }

    /**
     * Reject a location change request
     */
    public function rejectLocationRequest(Shop $shop, ShopLocationRequest $request)
    {
        try {
            Log::info('[ADMIN-LOCATION-REQUEST-REJECT] Starting rejection', [
                'shop_id' => $shop->id,
                'request_id' => $request->id,
            ]);

            // Update request status
            $request->update([
                'status' => 'rejected',
                'reviewed_at' => now(),
                'reviewed_by' => auth()->id(),
            ]);

            // Send FCM notification to vendor
            $fcmService = app(FCMService::class);
            $vendor = $shop->user;

            if ($vendor) {
                $fcmService->sendToUser(
                    $vendor,  // Pass the User object, not the ID
                    'Changement de localisation rejeté',
                    "Votre demande de changement de localisation pour {$shop->name} a été rejetée par l'administrateur.",
                    [
                        'type' => 'location_request_rejected',
                        'shop_id' => $shop->id,
                        'request_id' => $request->id,
                    ]
                );

                Log::info('[ADMIN-LOCATION-REQUEST-REJECT] FCM notification sent', [
                    'vendor_id' => $vendor->id,
                    'shop_id' => $shop->id,
                ]);
            }

            Log::info('[ADMIN-LOCATION-REQUEST-REJECT] Rejection successful');

            return redirect()->route('admin.shops.show', $shop)
                ->with('success', 'Demande de changement de localisation rejetée. Le vendeur a été notifié.');
        } catch (\Exception $e) {
            Log::error('[ADMIN-LOCATION-REQUEST-REJECT] Error rejecting location request', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return redirect()->back()
                ->with('error', 'Erreur lors du rejet de la demande: ' . $e->getMessage());
        }
    }
}