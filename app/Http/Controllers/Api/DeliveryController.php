<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\User;
use App\Services\OrderService;
use App\Services\WalletService;
use App\Services\FirebaseMessagingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DeliveryController extends Controller
{
    protected OrderService $orderService;
    protected WalletService $walletService;
    protected FirebaseMessagingService $fcmService;

    public function __construct(
        OrderService $orderService,
        WalletService $walletService,
        FirebaseMessagingService $fcmService
    ) {
        $this->orderService = $orderService;
        $this->walletService = $walletService;
        $this->fcmService = $fcmService;
    }

    /**
     * Get pending delivery requests for this delivery person.
     * Inclut : commandes assignées directement OU commandes de sa company (confirmed, pas encore assignées).
     */
    public function pendingRequests(Request $request)
    {
        $user = $request->user();

        // Commandes assignées directement à ce livreur
        $directOrders = Order::with(['items.product.primaryImage', 'user', 'deliveryCompany'])
            ->where('delivery_person_id', $user->id)
            ->whereIn('status', ['preparing', 'confirmed'])
            ->orderBy('created_at', 'desc')
            ->get();

        // Commandes de sa delivery company (confirmed, pas encore de delivery_person)
        $companyIds = \App\Models\DelivererCodeSync::where('user_id', $user->id)
            ->active()
            ->pluck('company_id');

        $companyOrders = collect();
        if ($companyIds->isNotEmpty()) {
            $companyOrders = Order::with(['items.product.primaryImage', 'user', 'deliveryCompany'])
                ->whereIn('delivery_company_id', $companyIds)
                ->whereNull('delivery_person_id')
                ->where('status', 'confirmed')
                ->orderBy('created_at', 'desc')
                ->get();
        }

        $allOrders = $directOrders->merge($companyOrders)->unique('id');

        return response()->json([
            'success' => true,
            'requests' => $allOrders->map(fn($order) => $this->formatDeliveryRequest($order)),
        ]);
    }

    /**
     * Accept a delivery request.
     * Le livreur s'auto-assigne et passe la commande en "shipped".
     * FCM envoyé au client.
     */
    public function accept(Request $request, $id)
    {
        $user = $request->user();

        $companyIds = \App\Models\DelivererCodeSync::where('user_id', $user->id)
            ->active()
            ->pluck('company_id');

        try {
            $order = DB::transaction(function () use ($user, $id, $companyIds) {
                // lockForUpdate empêche 2 livreurs d'accepter en même temps
                $order = Order::lockForUpdate()
                    ->where(function ($q) use ($user, $companyIds) {
                        $q->where('delivery_person_id', $user->id)
                          ->orWhere(function ($q2) use ($companyIds) {
                              $q2->whereIn('delivery_company_id', $companyIds)
                                 ->whereNull('delivery_person_id');
                          });
                    })
                    ->whereIn('status', ['confirmed', 'preparing'])
                    ->findOrFail($id);

                // Double-check : si un autre livreur a pris entre-temps
                if ($order->delivery_person_id !== null && $order->delivery_person_id !== $user->id) {
                    throw new \Exception("Cette livraison a déjà été prise par un autre livreur.");
                }

                $order->update([
                    'delivery_person_id' => $user->id,
                    'status' => 'shipped',
                    'shipped_at' => now(),
                ]);

                return $order;
            });

            // FCM au client : livraison en cours + code de confirmation
            $client = $order->user;
            if ($client) {
                $this->fcmService->sendToUser(
                    $client,
                    'Livraison en cours !',
                    "Votre commande #{$order->order_number} est en cours de livraison par {$user->first_name}. Votre code de confirmation : {$order->confirmation_code}",
                    [
                        'type' => 'order_shipped',
                        'order_id' => (string) $order->id,
                        'order_number' => $order->order_number,
                        'confirmation_code' => $order->confirmation_code,
                        'deliverer_name' => $user->first_name . ' ' . $user->last_name,
                        'deliverer_phone' => $user->phone ?? '',
                    ]
                );
            }

            // Notifier les AUTRES livreurs de la company que la commande est prise
            $this->notifyOtherDeliverers($order, $user);

            Log::info("[DeliveryController] Delivery accepted", [
                'order_id' => $order->id,
                'deliverer_id' => $user->id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Livraison acceptée — course démarrée',
                'order' => $this->formatDeliveryRequest($order->fresh(['items.product.primaryImage', 'user', 'deliveryCompany'])),
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Complete a delivery — le LIVREUR entre le code secret donné par le client.
     *
     * Flow :
     * 1. Vérifie le confirmation_code
     * 2. Passe la commande en "delivered"
     * 3. Libère l'escrow du client (releaseEscrow — les fonds sont définitivement débités)
     * 4. Débloque les fonds du vendeur (unlockFunds — le vendeur peut retirer)
     * 5. Crédite le livreur (commission = delivery_fee)
     * 6. FCM au client ("Livraison confirmée, notez votre expérience")
     */
    public function complete(Request $request, $id)
    {
        $request->validate([
            'confirmation_code' => 'required|string|size:6',
        ]);

        $user = $request->user();

        $order = Order::with(['items.seller', 'user'])
            ->where('delivery_person_id', $user->id)
            ->where('status', 'shipped')
            ->findOrFail($id);

        // Vérifier le code secret
        if ($order->confirmation_code !== $request->confirmation_code) {
            return response()->json([
                'success' => false,
                'message' => 'Code de confirmation incorrect.',
            ], 422);
        }

        try {
            DB::transaction(function () use ($user, $order) {
                $walletProvider = str_replace('wallet_', '', $order->payment_method);
                if (!in_array($walletProvider, ['freemopay', 'paypal'])) {
                    $walletProvider = 'freemopay';
                }

                // 1. Marquer la commande comme livrée + supprimer le code
                $order->update([
                    'status' => 'delivered',
                    'delivered_at' => now(),
                    'confirmed_by_deliverer_at' => now(),
                    'confirmed_by_client_at' => now(),
                    'confirmation_code' => null, // Supprimer le code après validation
                ]);

                // 2. Libérer l'escrow du client (les fonds bloqués sont maintenant définitivement débités)
                $this->walletService->releaseEscrow(
                    $order->user,
                    (float) $order->total,
                    "Paiement commande #{$order->order_number} — livraison confirmée",
                    'order',
                    $order->id,
                    [],
                    $walletProvider
                );

                // 3. Débloquer les fonds du vendeur (il peut maintenant retirer)
                $sellers = $order->items->pluck('seller_id')->unique();
                foreach ($sellers as $sellerId) {
                    $seller = User::find($sellerId);
                    if ($seller) {
                        $sellerAmount = (float) $order->items
                            ->where('seller_id', $sellerId)
                            ->sum('total_price');

                        $this->walletService->unlockFunds(
                            $seller,
                            $sellerAmount,
                            "Vente confirmée #{$order->order_number} — fonds disponibles",
                            'order',
                            $order->id,
                            [],
                            $walletProvider
                        );
                    }
                }

                // 4. Débloquer les fonds de l'entreprise de livraison
                $deliveryFee = (float) $order->delivery_fee;
                if ($deliveryFee > 0 && $order->delivery_company_id) {
                    $deliveryCompany = \App\Models\DelivererCompany::find($order->delivery_company_id);
                    if ($deliveryCompany && $deliveryCompany->user_id) {
                        $companyUser = User::find($deliveryCompany->user_id);
                        if ($companyUser) {
                            $this->walletService->unlockFunds(
                                $companyUser,
                                $deliveryFee,
                                "Livraison confirmée #{$order->order_number} — commission disponible",
                                'order',
                                $order->id,
                                [],
                                $walletProvider
                            );

                            // FCM à l'entreprise de livraison
                            $this->fcmService->sendToUser(
                                $companyUser,
                                'Commission débloquée !',
                                "Livraison #{$order->order_number} confirmée. " . number_format($deliveryFee, 0, ',', ' ') . " FCFA disponibles.",
                                [
                                    'type' => 'delivery_commission_released',
                                    'order_id' => (string) $order->id,
                                    'amount' => (string) $deliveryFee,
                                ]
                            );
                        }
                    }
                }

                // 5. FCM au client
                $client = $order->user;
                if ($client) {
                    $this->fcmService->sendToUser(
                        $client,
                        'Livraison confirmée !',
                        "Votre commande #{$order->order_number} a été livrée avec succès. Notez votre expérience !",
                        [
                            'type' => 'order_delivered',
                            'order_id' => (string) $order->id,
                            'order_number' => $order->order_number,
                        ]
                    );
                }

                // FCM au vendeur
                foreach ($sellers as $sellerId) {
                    $seller = User::find($sellerId);
                    if ($seller) {
                        $this->fcmService->sendToUser(
                            $seller,
                            'Livraison confirmée — fonds disponibles',
                            "La commande #{$order->order_number} a été livrée. Vos fonds sont maintenant retirables.",
                            [
                                'type' => 'order_delivered_vendor',
                                'order_id' => (string) $order->id,
                                'order_number' => $order->order_number,
                            ]
                        );
                    }
                }

                Log::info("[DeliveryController] Delivery completed — escrow released", [
                    'order_id' => $order->id,
                    'deliverer_id' => $user->id,
                    'delivery_fee' => $deliveryFee,
                ]);
            });

            return response()->json([
                'success' => true,
                'message' => 'Livraison confirmée ! Fonds libérés.',
            ]);

        } catch (\Exception $e) {
            Log::error("[DeliveryController] Error completing delivery: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Get active deliveries (shipped status)
     */
    public function activeDeliveries(Request $request)
    {
        $user = $request->user();

        $orders = Order::with(['items.product.primaryImage', 'user', 'deliveryCompany'])
            ->where('delivery_person_id', $user->id)
            ->where('status', 'shipped')
            ->orderBy('shipped_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'deliveries' => $orders->map(fn($order) => $this->formatDeliveryRequest($order)),
        ]);
    }

    /**
     * Check if a location is within any delivery zone
     */
    public function checkDeliveryAvailability(Request $request)
    {
        $request->validate([
            'latitude' => 'required|numeric',
            'longitude' => 'required|numeric',
        ]);

        $latitude = $request->latitude;
        $longitude = $request->longitude;

        \Log::info('========================================');
        \Log::info('🚚 DELIVERY AVAILABILITY CHECK START');
        \Log::info('========================================');
        \Log::info('📍 Requested Position:', [
            'latitude' => $latitude,
            'longitude' => $longitude,
        ]);

        // Get all active delivery zones with their companies and users
        $deliveryZones = \App\Models\DeliveryZone::where('is_active', true)
            ->with(['delivererCompany.user'])
            ->get();

        \Log::info('📦 Total Active Delivery Zones Found: ' . $deliveryZones->count());

        if ($deliveryZones->isEmpty()) {
            \Log::warning('⚠️ No active delivery zones found in database');
            return response()->json([
                'success' => false,
                'available' => false,
                'message' => 'Aucune zone de livraison disponible pour le moment',
            ]);
        }

        // Log each zone with detailed info
        \Log::info('📋 Analyzing Each Delivery Zone:');
        \Log::info('----------------------------------------');

        // Check if the point is within any delivery zone
        // Simple radius-based check using center coordinates
        $nearbyZones = [];
        $zonesWithSyncedDeliverers = 0;
        $zonesWithoutSyncedDeliverers = 0;

        foreach ($deliveryZones as $index => $zone) {
            $zoneNumber = $index + 1;
            \Log::info("📍 Zone #{$zoneNumber}: {$zone->name}");
            \Log::info("  ├─ Zone ID: {$zone->id}");
            \Log::info("  ├─ Company: {$zone->delivererCompany->name}");
            \Log::info("  ├─ Company ID: {$zone->delivererCompany->id}");

            // Check if company has a synchronized deliverer (user)
            $hasSyncedDeliverer = $zone->delivererCompany->user_id !== null;
            \Log::info("  ├─ Has Synced Deliverer: " . ($hasSyncedDeliverer ? 'YES ✅' : 'NO ❌'));

            if ($hasSyncedDeliverer) {
                $zonesWithSyncedDeliverers++;
                $delivererUser = $zone->delivererCompany->user;
                \Log::info("  ├─ Deliverer User ID: {$zone->delivererCompany->user_id}");
                if ($delivererUser) {
                    \Log::info("  ├─ Deliverer Name: {$delivererUser->first_name} {$delivererUser->last_name}");
                    \Log::info("  ├─ Deliverer Phone: {$delivererUser->phone}");
                    \Log::info("  ├─ Deliverer Role: {$delivererUser->role}");
                }
            } else {
                $zonesWithoutSyncedDeliverers++;
                \Log::info("  ├─ ⚠️ Company has NO synchronized deliverer yet");
            }

            if ($zone->center_latitude && $zone->center_longitude) {
                \Log::info("  ├─ Zone Center: ({$zone->center_latitude}, {$zone->center_longitude})");

                // Calculate distance in kilometers using Haversine formula
                $distance = $this->calculateDistance(
                    $latitude,
                    $longitude,
                    (float)$zone->center_latitude,
                    (float)$zone->center_longitude
                );

                \Log::info("  ├─ Distance from requested position: " . round($distance, 2) . " km");

                // Consider zone available if within 10km radius
                // This is a simple check; you can implement more complex polygon checking
                $isWithinRadius = $distance <= 10;
                \Log::info("  ├─ Within 10km radius: " . ($isWithinRadius ? 'YES ✅' : 'NO ❌'));

                if ($isWithinRadius && $hasSyncedDeliverer) {
                    \Log::info("  └─ ✅ ZONE QUALIFIES (within radius AND has synced deliverer)");
                    $nearbyZones[] = [
                        'id' => $zone->id,
                        'name' => $zone->name,
                        'distance' => round($distance, 2),
                        'company' => [
                            'id' => $zone->delivererCompany->id,
                            'name' => $zone->delivererCompany->name,
                            'user_id' => $zone->delivererCompany->user_id,
                        ],
                    ];
                } elseif ($isWithinRadius && !$hasSyncedDeliverer) {
                    \Log::info("  └─ ⚠️ ZONE EXCLUDED (within radius but NO synced deliverer)");
                } else {
                    \Log::info("  └─ ❌ ZONE EXCLUDED (outside 10km radius)");
                }
            } else {
                \Log::info("  ├─ Zone Center: NOT SET");
                \Log::info("  └─ ❌ ZONE EXCLUDED (no center coordinates)");
            }

            \Log::info('');
        }

        \Log::info('========================================');
        \Log::info('📊 SUMMARY:');
        \Log::info("  ├─ Total Zones Analyzed: {$deliveryZones->count()}");
        \Log::info("  ├─ Zones with Synced Deliverers: {$zonesWithSyncedDeliverers}");
        \Log::info("  ├─ Zones without Synced Deliverers: {$zonesWithoutSyncedDeliverers}");
        \Log::info("  └─ Qualifying Zones (nearby + synced): " . count($nearbyZones));
        \Log::info('========================================');

        if (empty($nearbyZones)) {
            \Log::warning('❌ RESULT: No qualifying delivery zones found');
            \Log::warning('Reason: Either no zones within radius OR no zones have synced deliverers');
            \Log::info('========================================');

            return response()->json([
                'success' => false,
                'available' => false,
                'message' => 'Désolé, aucune zone de livraison ne couvre cette position. Veuillez choisir un emplacement dans une zone desservie.',
            ]);
        }

        // Sort by distance
        usort($nearbyZones, fn($a, $b) => $a['distance'] <=> $b['distance']);

        \Log::info('✅ RESULT: Delivery Available!');
        \Log::info('📍 Qualifying Zones (sorted by distance):');
        foreach ($nearbyZones as $index => $zone) {
            $rank = $index + 1;
            \Log::info("  {$rank}. {$zone['name']} - {$zone['distance']} km - Company: {$zone['company']['name']}");
        }
        \Log::info('🏆 Nearest Zone: ' . $nearbyZones[0]['name'] . ' (' . $nearbyZones[0]['distance'] . ' km)');
        \Log::info('========================================');

        return response()->json([
            'success' => true,
            'available' => true,
            'message' => 'Zone de livraison disponible',
            'zones' => $nearbyZones,
            'nearest_zone' => $nearbyZones[0] ?? null,
        ]);
    }

    /**
     * Get all delivery partners with calculated delivery prices.
     * If product_id is provided, calculates price based on the company's pricing type
     * (fixed, weight_category, or volumetric_weight).
     *
     * GET /v1/delivery/partners?product_id=X&latitude=Y&longitude=Z
     */
    public function getDeliveryPartners(Request $request)
    {
        try {
            $productId = $request->input('product_id');
            $latitude = $request->input('latitude');
            $longitude = $request->input('longitude');

            // Si product_id fourni, retourner les partenaires avec prix calculé
            if ($productId) {
                $partners = $this->orderService->getDeliveryPartnersWithPricing(
                    (int) $productId,
                    $latitude ? (float) $latitude : null,
                    $longitude ? (float) $longitude : null
                );

                return response()->json([
                    'success' => true,
                    'partners' => $partners,
                    'total' => count($partners),
                ]);
            }

            // Sans product_id : retourner la liste simple (legacy)
            $deliveryCompanies = \App\Models\DelivererCompany::where('is_active', true)
                ->with(['deliveryZones' => function($query) {
                    $query->where('is_active', true)
                          ->whereNotNull('center_latitude')
                          ->whereNotNull('center_longitude');
                }, 'user'])
                ->get();

            $deliverers = [];
            foreach ($deliveryCompanies as $company) {
                foreach ($company->deliveryZones as $zone) {
                    $deliverers[] = [
                        'id' => $company->id,
                        'name' => $company->name,
                        'phone' => $company->phone,
                        'email' => $company->email,
                        'description' => $company->description,
                        'logo' => $company->logo ? asset('storage/' . $company->logo) : null,
                        'zone' => [
                            'id' => $zone->id,
                            'name' => $zone->name,
                            'latitude' => (float) $zone->center_latitude,
                            'longitude' => (float) $zone->center_longitude,
                        ],
                        'user' => $company->user ? [
                            'id' => $company->user->id,
                            'name' => $company->user->first_name . ' ' . $company->user->last_name,
                            'phone' => $company->user->phone,
                        ] : null,
                    ];
                }
            }

            return response()->json([
                'success' => true,
                'deliverers' => $deliverers,
            ]);

        } catch (\Exception $e) {
            \Log::error('[DeliveryController] Error fetching delivery partners: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des partenaires de livraison',
            ], 500);
        }
    }

    /**
     * Calculate distance between two coordinates using Haversine formula
     */
    private function calculateDistance($lat1, $lon1, $lat2, $lon2)
    {
        $earthRadius = 6371; // Earth's radius in kilometers

        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat / 2) * sin($dLat / 2) +
             cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
             sin($dLon / 2) * sin($dLon / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }

    /**
     * Notifier les autres livreurs de la company que la commande a été prise.
     * Ils recevront un refresh de leur dashboard.
     */
    private function notifyOtherDeliverers(Order $order, User $acceptedBy): void
    {
        if (!$order->delivery_company_id) return;

        $otherSyncs = \App\Models\DelivererCodeSync::where('company_id', $order->delivery_company_id)
            ->active()
            ->where('user_id', '!=', $acceptedBy->id)
            ->with('user')
            ->get();

        foreach ($otherSyncs as $sync) {
            if ($sync->user) {
                $this->fcmService->sendToUser(
                    $sync->user,
                    'Commande prise',
                    "La commande #{$order->order_number} a été acceptée par un autre livreur.",
                    [
                        'type' => 'delivery_taken',
                        'order_id' => (string) $order->id,
                        'order_number' => $order->order_number,
                    ]
                );
            }
        }

        Log::info("[DeliveryController] Notified " . $otherSyncs->count() . " other deliverers that order #{$order->order_number} was taken");
    }

    private function formatDeliveryRequest($order): array
    {
        return [
            'id' => $order->id,
            'order_number' => $order->order_number,
            'status' => $order->status,
            'total' => (float) $order->total,
            'delivery_fee' => (float) $order->delivery_fee,
            'delivery_address' => $order->delivery_address,
            'delivery_latitude' => $order->delivery_latitude,
            'delivery_longitude' => $order->delivery_longitude,
            'customer' => $order->user ? [
                'id' => $order->user->id,
                'name' => $order->user->name,
                'phone' => $order->user->phone,
                'address' => $order->user->address,
            ] : null,
            'items' => $order->items->map(fn($item) => [
                'product_name' => $item->product->name ?? 'Produit',
                'quantity' => $item->quantity,
            ]),
            'items_count' => $order->items->count(),
            'created_at' => $order->created_at->toIso8601String(),
            'shipped_at' => $order->shipped_at?->toIso8601String(),
        ];
    }
}
