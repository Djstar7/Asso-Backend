<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Shop;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    /**
     * Apply to become a vendor
     */
    public function applyVendor(Request $request)
    {
        \Log::info('========== VENDOR APPLY START ==========');
        \Log::info('[VENDOR_APPLY] Request data:', [
            'fields' => $request->except(['shop_logo', 'avatar']),
            'has_shop_logo' => $request->hasFile('shop_logo'),
            'has_avatar' => $request->hasFile('avatar'),
        ]);

        try {
            $request->validate([
                'shop_name' => 'required|string|max:255',
                'shop_description' => 'nullable|string',
                'shop_address' => 'nullable|string',
                'shop_logo' => 'nullable|image|max:2048',
                'shop_latitude' => 'nullable|numeric',
                'shop_longitude' => 'nullable|numeric',
                'categories' => 'nullable|array',
                'categories.*' => 'string',
                'company_name' => 'nullable|string|max:255',
                'first_name' => 'nullable|string|max:255',
                'last_name' => 'nullable|string|max:255',
                'gender' => 'nullable|string|max:50',
                'account_type' => 'nullable|string|max:50',
            ]);
            \Log::info('[VENDOR_APPLY] Validation passed');

            // Validation supplémentaire pour l'adresse
            if ($request->filled('shop_address')) {
                $address = $request->shop_address;
                // Rejeter les adresses placeholder
                if (str_contains($address, 'Chargement') ||
                    str_contains($address, 'Loading') ||
                    empty(trim($address))) {
                    \Log::error('[VENDOR_APPLY] Invalid address detected:', [
                        'address' => $address,
                    ]);
                    return response()->json([
                        'success' => false,
                        'message' => 'L\'adresse fournie est invalide. Veuillez réessayer.',
                    ], 422);
                }
            }
        } catch (\Illuminate\Validation\ValidationException $e) {
            \Log::error('[VENDOR_APPLY] Validation failed:', [
                'errors' => $e->errors(),
            ]);
            throw $e;
        }

        $user = $request->user();
        \Log::info('[VENDOR_APPLY] User info:', [
            'user_id' => $user->id,
            'current_role' => $user->role,
            'phone' => $user->phone,
        ]);

        if ($user->hasAnyRole(['vendeur', 'vendor'])) {
            \Log::warning('[VENDOR_APPLY] User is already a vendor', [
                'user_id' => $user->id,
                'roles' => $user->getRoles(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Vous êtes déjà vendeur',
            ], 422);
        }

        // Update user profile if fields are provided
        $userUpdates = [];

        if ($request->filled('first_name')) {
            $userUpdates['first_name'] = $request->first_name;
        }

        if ($request->filled('last_name')) {
            $userUpdates['last_name'] = $request->last_name;
        }

        if ($request->filled('gender')) {
            // Convert French gender values to English for database
            $genderMap = [
                'Homme' => 'male',
                'Femme' => 'female',
                'homme' => 'male',
                'femme' => 'female',
                'male' => 'male',
                'female' => 'female',
                'other' => 'other',
            ];

            $gender = $request->gender;
            $userUpdates['gender'] = $genderMap[$gender] ?? 'other';

            \Log::info('[VENDOR_APPLY] Gender conversion:', [
                'original' => $gender,
                'converted' => $userUpdates['gender'],
            ]);
        }

        if ($request->filled('company_name')) {
            $userUpdates['company_name'] = $request->company_name;
        } elseif ($request->filled('account_type') && $request->account_type === 'Entreprise') {
            $userUpdates['company_name'] = $request->shop_name;
        }

        \Log::info('[VENDOR_APPLY] User updates prepared:', $userUpdates);

        // Create shop
        $shopData = [
            'user_id' => $user->id,
            'name' => $request->shop_name,
            'description' => $request->shop_description,
            'address' => $request->shop_address,
            'latitude' => $request->shop_latitude,
            'longitude' => $request->shop_longitude,
            'categories' => $request->categories ?? [],
            'status' => 'inactive', // Shop starts as inactive, admin will activate after verification
        ];

        \Log::info('[VENDOR_APPLY] Shop data prepared:', $shopData);

        // Handle shop logo upload
        if ($request->hasFile('shop_logo')) {
            try {
                $logoPath = $request->file('shop_logo')->store('shops', 'public');
                $shopData['logo'] = $logoPath;
                \Log::info('[VENDOR_APPLY] Shop logo uploaded:', ['path' => $logoPath]);
            } catch (\Exception $e) {
                \Log::error('[VENDOR_APPLY] Shop logo upload failed:', [
                    'error' => $e->getMessage(),
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'Erreur lors de l\'upload du logo',
                ], 500);
            }
        }

        // Handle avatar upload
        if ($request->hasFile('avatar')) {
            try {
                $avatarPath = $request->file('avatar')->store('avatars', 'public');
                $userUpdates['avatar'] = $avatarPath;
                \Log::info('[VENDOR_APPLY] Avatar uploaded:', ['path' => $avatarPath]);
            } catch (\Exception $e) {
                \Log::error('[VENDOR_APPLY] Avatar upload failed:', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        try {
            // Create shop
            $shop = Shop::create($shopData);
            \Log::info('[VENDOR_APPLY] Shop created successfully:', [
                'shop_id' => $shop->id,
                'shop_slug' => $shop->slug,
            ]);

            // Update user info
            if (!empty($userUpdates)) {
                $user->update($userUpdates);
            }

            // Add vendor role (keeps existing roles)
            $user->addRole('vendeur');

            \Log::info('[VENDOR_APPLY] User updated successfully:', [
                'user_id' => $user->id,
                'roles' => $user->getRoles(),
                'primary_role' => $user->role,
            ]);

            \Log::info('========== VENDOR APPLY SUCCESS ==========');

            return response()->json([
                'success' => true,
                'message' => 'Vous êtes maintenant vendeur !',
                'shop' => [
                    'id' => $shop->id,
                    'name' => $shop->name,
                    'slug' => $shop->slug,
                    'logo' => $shop->logo ? asset('storage/' . $shop->logo) : null,
                    'description' => $shop->description,
                    'address' => $shop->address,
                    'latitude' => $shop->latitude,
                    'longitude' => $shop->longitude,
                    'categories' => $shop->categories,
                    'status' => $shop->status,
                    'is_certified' => (bool) $shop->is_certified,
                ],
                'user' => [
                    'id' => $user->id,
                    'first_name' => $user->first_name,
                    'last_name' => $user->last_name,
                    'name' => $user->name,
                    'role' => $user->role,
                    'roles' => $user->getRoles(),
                    'company_name' => $user->company_name,
                    'gender' => $user->gender,
                    'avatar' => $user->avatar ? asset('storage/' . $user->avatar) : null,
                ],
            ]);
        } catch (\Exception $e) {
            \Log::error('[VENDOR_APPLY] Failed to create shop or update user:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            \Log::error('========== VENDOR APPLY FAILED ==========');

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la création de la boutique',
                'error' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Apply to become a delivery person
     */
    public function applyDelivery(Request $request)
    {
        $request->validate([
            'vehicle_type' => 'nullable|string|max:50',
            'address' => 'nullable|string',
            'latitude' => 'nullable|numeric',
            'longitude' => 'nullable|numeric',
        ]);

        $user = $request->user();

        if ($user->role === 'livreur') {
            return response()->json([
                'success' => false,
                'message' => 'Vous êtes déjà livreur',
            ], 422);
        }

        $user->update([
            'role' => 'livreur',
            'address' => $request->address ?? $user->address,
            'latitude' => $request->latitude ?? $user->latitude,
            'longitude' => $request->longitude ?? $user->longitude,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Vous êtes maintenant livreur !',
            'user' => [
                'id' => $user->id,
                'role' => $user->role,
                'address' => $user->address,
            ],
        ]);
    }

    /**
     * Get vendor dashboard data
     */
    public function vendorDashboard(Request $request)
    {
        \Log::info('========== VENDOR DASHBOARD START ==========');

        $user = $request->user();
        \Log::info('[VENDOR_DASHBOARD] Request from user:', [
            'user_id' => $user->id,
            'role' => $user->role,
            'phone' => $user->phone,
        ]);

        // Check if user is a vendor
        if (!$user->hasAnyRole(['vendeur', 'vendor'])) {
            \Log::warning('[VENDOR_DASHBOARD] Access denied - user is not a vendor:', [
                'user_id' => $user->id,
                'roles' => $user->getRoles(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'êtes pas vendeur',
            ], 403);
        }

        try {
            $user->load(['shops.products']);
            \Log::info('[VENDOR_DASHBOARD] User shops loaded:', [
                'shops_count' => $user->shops->count(),
            ]);

            $shop = $user->shops->first();

            if (!$shop) {
                \Log::error('[VENDOR_DASHBOARD] No shop found for vendor:', [
                    'user_id' => $user->id,
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'Aucune boutique trouvée',
                ], 404);
            }

            \Log::info('[VENDOR_DASHBOARD] Shop found:', [
                'shop_id' => $shop->id,
                'shop_name' => $shop->name,
            ]);

            $products = $shop->products()->with(['primaryImage', 'images'])->get();
            \Log::info('[VENDOR_DASHBOARD] Products loaded:', [
                'products_count' => $products->count(),
            ]);

            $ordersCount = \DB::table('order_items')
                ->where('seller_id', $user->id)
                ->distinct('order_id')
                ->count('order_id');

            // Calculate total sales from completed orders
            $totalSales = \DB::table('order_items')
                ->join('orders', 'order_items.order_id', '=', 'orders.id')
                ->where('order_items.seller_id', $user->id)
                ->whereIn('orders.status', ['delivered', 'completed'])
                ->sum('order_items.total_price');

            // Calculate average rating from product reviews
            $averageRating = 0.0;
            $totalReviews = 0;

            if ($products->count() > 0) {
                $productIds = $products->pluck('id');
                $reviews = \DB::table('product_reviews')
                    ->whereIn('product_id', $productIds)
                    ->get();

                if ($reviews->count() > 0) {
                    $totalReviews = $reviews->count();
                    $sumRatings = $reviews->sum('rating');
                    $averageRating = round($sumRatings / $totalReviews, 1);
                }
            }

            // Package info
            $activePackage = $user->activeVendorPackage;
            $packageInfo = null;
            if ($activePackage) {
                // Load package relationship only if package_id is not null
                if ($activePackage->package_id) {
                    $activePackage->load('package');
                }

                // Prepare package data based on whether it's cumulative or not
                $packageData = $activePackage->package_id && $activePackage->package
                    ? [
                        'id' => $activePackage->package->id,
                        'name' => $activePackage->package->name,
                        'description' => $activePackage->package->description,
                        'price' => (float) $activePackage->package->price,
                        'formatted_price' => $activePackage->package->formatted_price,
                        'duration_days' => $activePackage->package->duration_days,
                        'formatted_duration' => $activePackage->package->formatted_duration,
                        'storage_size_mb' => $activePackage->package->storage_size_mb,
                        'formatted_storage_size' => $activePackage->package->formatted_storage_size,
                    ]
                    : [
                        'id' => null,
                        'name' => $activePackage->custom_name ?? 'Espace Cumulé',
                        'description' => 'Package personnalisé avec espace cumulé',
                        'price' => null,
                        'formatted_price' => 'Variable',
                        'duration_days' => null,
                        'formatted_duration' => 'Personnalisé',
                        'storage_size_mb' => (float) $activePackage->storage_total_mb,
                        'formatted_storage_size' => number_format($activePackage->storage_total_mb, 0) . ' MB',
                    ];

                $packageInfo = [
                    'has_package' => true,
                    'vendor_package' => [
                        'id' => $activePackage->id,
                        'storage_total_mb' => (float) $activePackage->storage_total_mb,
                        'storage_used_mb' => (float) $activePackage->storage_used_mb,
                        'storage_remaining_mb' => (float) $activePackage->storage_remaining_mb,
                        'storage_percentage_used' => $activePackage->storage_total_mb > 0
                            ? round(($activePackage->storage_used_mb / $activePackage->storage_total_mb) * 100, 2)
                            : 0,
                        'purchased_at' => $activePackage->purchased_at->toIso8601String(),
                        'expires_at' => $activePackage->expires_at->toIso8601String(),
                        'days_remaining' => max(0, now()->diffInDays($activePackage->expires_at, false)),
                        'status' => $activePackage->status,
                        'payment_reference' => $activePackage->payment_reference,
                        'is_cumulative' => $activePackage->package_id === null,
                        'package' => $packageData,
                    ],
                ];
            } else {
                $packageInfo = ['has_package' => false];
            }

            \Log::info('[VENDOR_DASHBOARD] Stats calculated:', [
                'orders_count' => $ordersCount,
                'total_sales' => $totalSales,
                'products_count' => $products->count(),
                'reviews_count' => $totalReviews,
                'average_rating' => $averageRating,
                'has_package' => $packageInfo['has_package'],
            ]);

            $responseData = [
                'success' => true,
                'data' => [
                    'shop' => [
                        'id' => $shop->id,
                        'name' => $shop->name,
                        'slug' => $shop->slug,
                        'logo' => $shop->logo ? asset('storage/' . $shop->logo) : null,
                        'description' => $shop->description,
                        'address' => $shop->address,
                        'latitude' => $shop->latitude,
                        'longitude' => $shop->longitude,
                        'categories' => $shop->categories,
                        'status' => $shop->status,
                        'is_certified' => (bool) $shop->is_certified,
                    ],
                    'stats' => [
                        'total_orders' => $ordersCount,
                        'total_sales' => (float) $totalSales,
                        'total_products' => $products->count(),
                        'total_reviews' => $totalReviews,
                        'rating' => $averageRating,
                    ],
                    'package' => $packageInfo,
                    'certification' => [
                        'is_certified' => $shop->isCertified(),
                        'certified_at' => $shop->certified_at?->toIso8601String(),
                        'certification_expires_at' => $shop->certification_expires_at?->toIso8601String(),
                        'is_expiring_soon' => $shop->isCertificationExpiringSoon(),
                        'days_until_expiry' => $shop->daysUntilCertificationExpiry(),
                    ],
                    'verification' => [
                        'status' => $shop->status,
                        'message' => $this->getVerificationMessage($shop->status, $shop->rejection_reason),
                    ],
                    'products' => $products->map(fn($p) => [
                        'id' => $p->id,
                        'name' => $p->name,
                        'price' => (float) $p->price,
                        'stock' => $p->stock,
                        'status' => $p->status,
                        'primary_image' => $p->primaryImage ? asset('storage/' . $p->primaryImage->image_path) : null,
                    ]),
                ],
            ];

            \Log::info('========== VENDOR DASHBOARD SUCCESS ==========');

            return response()->json($responseData);
        } catch (\Exception $e) {
            \Log::error('[VENDOR_DASHBOARD] Error loading dashboard:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            \Log::error('========== VENDOR DASHBOARD FAILED ==========');

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors du chargement du tableau de bord',
                'error' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Get delivery dashboard data
     */
    public function deliveryDashboard(Request $request)
    {
        $user = $request->user();

        // Load deliverer company with zones
        $user->load(['delivererCompany.deliveryZones.pricelist']);

        // 1. Commandes assignées directement à ce livreur
        $directOrders = \App\Models\Order::where('delivery_person_id', $user->id)
            ->with(['items.product.primaryImage', 'user', 'deliveryCompany'])
            ->orderBy('created_at', 'desc')
            ->get();

        // 2. Commandes de sa company (confirmed, pas encore de delivery_person)
        $companyIds = \App\Models\DelivererCodeSync::where('user_id', $user->id)
            ->where('is_active', true)
            ->where('is_banned', false)
            ->pluck('company_id');

        $companyOrders = collect();
        if ($companyIds->isNotEmpty()) {
            $companyOrders = \App\Models\Order::whereIn('delivery_company_id', $companyIds)
                ->whereNull('delivery_person_id')
                ->where('status', 'confirmed')
                ->with(['items.product.primaryImage', 'user', 'deliveryCompany'])
                ->orderBy('created_at', 'desc')
                ->get();
        }

        $deliveries = $directOrders->merge($companyOrders)->unique('id')->sortByDesc('created_at')->values();

        $stats = [
            'total_deliveries' => $deliveries->count(),
            'completed' => $directOrders->where('status', 'delivered')->count(),
            'in_progress' => $directOrders->where('status', 'shipped')->count(),
            'pending' => $deliveries->whereIn('status', ['confirmed', 'preparing'])->count(),
        ];

        // Get company info if exists
        $companyData = null;
        if ($user->delivererCompany) {
            $company = $user->delivererCompany;
            $companyData = [
                'id' => $company->id,
                'user_id' => $company->user_id,
                'name' => $company->name,
                'phone' => $company->phone,
                'email' => $company->email,
                'description' => $company->description,
                'logo' => $company->logo ? asset('storage/' . $company->logo) : null,
                'is_active' => $company->is_active,
                'created_at' => $company->created_at?->toIso8601String(),
                'updated_at' => $company->updated_at?->toIso8601String(),
                'zones' => $company->deliveryZones->map(function ($zone) {
                    return [
                        'id' => $zone->id,
                        'deliverer_company_id' => $zone->deliverer_company_id,
                        'name' => $zone->name,
                        'zone_data' => $zone->zone_data,
                        'center_latitude' => $zone->center_latitude,
                        'center_longitude' => $zone->center_longitude,
                        'is_active' => $zone->is_active,
                        'created_at' => $zone->created_at?->toIso8601String(),
                        'updated_at' => $zone->updated_at?->toIso8601String(),
                        'pricelist' => $zone->pricelist ? [
                            'id' => $zone->pricelist->id,
                            'delivery_zone_id' => $zone->pricelist->delivery_zone_id,
                            'pricing_type' => $zone->pricelist->pricing_type,
                            'pricing_data' => $zone->pricelist->pricing_data,
                            'is_active' => $zone->pricelist->is_active,
                            'created_at' => $zone->pricelist->created_at?->toIso8601String(),
                            'updated_at' => $zone->pricelist->updated_at?->toIso8601String(),
                        ] : null,
                    ];
                }),
            ];
        }

        return response()->json([
            'success' => true,
            'company' => $companyData,
            'deliveries' => $deliveries->map(fn($order) => [
                'id' => $order->id,
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'status' => $order->status,
                'total' => (float) $order->total,
                'delivery_fee' => (float) $order->delivery_fee,
                'commission' => (float) $order->delivery_fee,
                'delivery_address' => $order->delivery_address,
                'delivery_latitude' => $order->delivery_latitude,
                'delivery_longitude' => $order->delivery_longitude,
                'customer_name' => $order->user ? $order->user->name : 'Client',
                'customer_phone' => $order->user ? $order->user->phone : '',
                'customer' => $order->user ? [
                    'id' => $order->user->id,
                    'name' => $order->user->name,
                    'phone' => $order->user->phone,
                    'address' => $order->user->address,
                ] : null,
                'delivery_company' => $order->deliveryCompany ? [
                    'id' => $order->deliveryCompany->id,
                    'name' => $order->deliveryCompany->name,
                ] : null,
                'pickup_address' => '',
                'items_count' => $order->items->count(),
                'created_at' => $order->created_at->toIso8601String(),
                'confirmed_at' => $order->confirmed_at?->toIso8601String(),
                'shipped_at' => $order->shipped_at?->toIso8601String(),
                'delivered_at' => $order->delivered_at?->toIso8601String(),
            ]),
            'stats' => $stats,
        ]);
    }

    /**
     * Get verification message based on shop status
     */
    private function getVerificationMessage(string $status, ?string $rejectionReason = null): string
    {
        return match($status) {
            'active' => 'Votre boutique est vérifiée et active',
            'pending' => 'Votre boutique est en cours de vérification par notre équipe',
            'rejected' => $rejectionReason
                ? "Votre boutique a été rejetée. Raison: {$rejectionReason}"
                : 'Votre boutique a été rejetée',
            'inactive' => 'Votre boutique est désactivée',
            default => 'Statut inconnu',
        };
    }
}
