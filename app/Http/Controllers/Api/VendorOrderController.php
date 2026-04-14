<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Models\DelivererCodeSync;
use App\Services\WalletService;
use App\Services\FirebaseMessagingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class VendorOrderController extends Controller
{
    protected WalletService $walletService;
    protected FirebaseMessagingService $fcmService;

    public function __construct(WalletService $walletService, FirebaseMessagingService $fcmService)
    {
        $this->walletService = $walletService;
        $this->fcmService = $fcmService;
    }

    /**
     * List orders for vendor (orders containing their products)
     */
    public function index(Request $request)
    {
        $user = $request->user();

        // Get order IDs where this vendor has items
        $orderIds = OrderItem::where('seller_id', $user->id)->pluck('order_id')->unique();

        $query = Order::with(['items' => function($q) use ($user) {
            $q->where('seller_id', $user->id)->with('product.primaryImage');
        }, 'user', 'deliveryPerson'])
            ->whereIn('id', $orderIds);

        if ($request->has('status') && $request->status) {
            $query->where('status', $request->status);
        }

        $orders = $query->orderBy('created_at', 'desc')
            ->paginate($request->get('per_page', 20));

        return response()->json([
            'success' => true,
            'orders' => $orders->getCollection()->map(fn($order) => $this->formatVendorOrder($order, $user->id)),
            'pagination' => [
                'current_page' => $orders->currentPage(),
                'last_page' => $orders->lastPage(),
                'per_page' => $orders->perPage(),
                'total' => $orders->total(),
                'has_more' => $orders->hasMorePages(),
            ],
        ]);
    }

    /**
     * Validate (confirm) an order
     *
     * Flow :
     * 1. Passe la commande en "confirmed"
     * 2. Crédite le wallet vendeur avec fonds BLOQUÉS (escrow) — il ne peut pas retirer
     * 3. Envoie FCM au client ("Commande validée, en cours de livraison")
     * 4. Envoie FCM au livreur ("Nouvelle livraison à effectuer")
     */
    public function validate(Request $request, $id)
    {
        $vendor = $request->user();
        $order = $this->getVendorOrder($vendor, $id);

        if ($order->status !== 'pending') {
            return response()->json(['success' => false, 'message' => 'Cette commande ne peut plus être validée'], 422);
        }

        try {
            DB::transaction(function () use ($vendor, $order) {
                // 1. Confirmer la commande
                $order->update([
                    'status' => 'confirmed',
                    'confirmed_at' => now(),
                ]);

                // 2. Déterminer le wallet provider depuis le payment_method
                $walletProvider = str_replace('wallet_', '', $order->payment_method);
                if (!in_array($walletProvider, ['freemopay', 'paypal'])) {
                    $walletProvider = 'freemopay';
                }

                // 3. Créditer le vendeur avec fonds BLOQUÉS (escrow)
                // Le vendeur reçoit le subtotal (hors frais de livraison), mais bloqué
                $vendorAmount = (float) $order->subtotal;

                // Créditer puis bloquer immédiatement
                $this->walletService->credit(
                    $vendor,
                    $vendorAmount,
                    null,
                    "Vente commande #{$order->order_number} (en attente livraison)",
                    ['order_id' => $order->id, 'escrow' => true],
                    $walletProvider
                );

                $this->walletService->lockFunds(
                    $vendor,
                    $vendorAmount,
                    "Escrow vente #{$order->order_number} — fonds bloqués jusqu'à livraison",
                    'order',
                    $order->id,
                    [],
                    $walletProvider
                );

                // 3b. Créditer l'entreprise de livraison avec fonds BLOQUÉS (delivery_fee)
                $deliveryFee = (float) $order->delivery_fee;
                if ($deliveryFee > 0 && $order->delivery_company_id) {
                    $deliveryCompany = \App\Models\DelivererCompany::find($order->delivery_company_id);
                    if ($deliveryCompany && $deliveryCompany->user_id) {
                        $companyUser = \App\Models\User::find($deliveryCompany->user_id);
                        if ($companyUser) {
                            $this->walletService->credit(
                                $companyUser,
                                $deliveryFee,
                                null,
                                "Commission livraison #{$order->order_number} (en attente livraison)",
                                ['order_id' => $order->id, 'escrow' => true],
                                $walletProvider
                            );

                            $this->walletService->lockFunds(
                                $companyUser,
                                $deliveryFee,
                                "Escrow livraison #{$order->order_number} — bloqué jusqu'à livraison",
                                'order',
                                $order->id,
                                [],
                                $walletProvider
                            );
                        }
                    }
                }

                // 4. Notifications FCM

                // Au client
                $client = $order->user;
                if ($client) {
                    $this->fcmService->sendToUser(
                        $client,
                        'Commande validée !',
                        "Votre commande #{$order->order_number} a été acceptée par le vendeur. En attente du livreur.",
                        [
                            'type' => 'order_confirmed',
                            'order_id' => (string) $order->id,
                            'order_number' => $order->order_number,
                        ]
                    );
                }

                // Au livreur (via la delivery company assignée)
                $this->notifyDeliveryCompany($order);
            });

            // 5. Dispatcher le job de vérification après 5 minutes
            \App\Jobs\CheckDeliveryAcceptanceJob::dispatch($order->id)
                ->delay(now()->addMinutes(5));

            return response()->json([
                'success' => true,
                'message' => 'Commande validée. Fonds crédités et bloqués en attente de livraison.',
                'order' => $this->formatVendorOrder(
                    $order->fresh(['items.product.primaryImage', 'user', 'deliveryPerson', 'deliveryCompany']),
                    $vendor->id
                ),
            ]);

        } catch (\Exception $e) {
            Log::error("[VendorOrderController] Erreur validation commande: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Reject an order
     *
     * Flow :
     * 1. Annule la commande
     * 2. Débloque les fonds du client (escrow)
     * 3. Envoie FCM au client ("Commande refusée, fonds restitués")
     */
    public function reject(Request $request, $id)
    {
        $request->validate(['reason' => 'nullable|string|max:500']);

        $vendor = $request->user();
        $order = $this->getVendorOrder($vendor, $id);

        if (!in_array($order->status, ['pending'])) {
            return response()->json(['success' => false, 'message' => 'Cette commande ne peut plus être refusée'], 422);
        }

        try {
            DB::transaction(function () use ($request, $order) {
                $cancelReason = $request->reason ?? 'Refusée par le vendeur';

                // 1. Annuler la commande
                $order->update([
                    'status' => 'cancelled',
                    'cancel_reason' => $cancelReason,
                    'cancelled_at' => now(),
                ]);

                // 2. Débloquer les fonds du client
                $walletProvider = str_replace('wallet_', '', $order->payment_method);
                if (in_array($walletProvider, ['freemopay', 'paypal'])) {
                    $client = $order->user;
                    if ($client) {
                        $this->walletService->unlockFunds(
                            $client,
                            (float) $order->total,
                            "Remboursement commande #{$order->order_number} — refusée par vendeur",
                            'order',
                            $order->id,
                            ['cancel_reason' => $cancelReason],
                            $walletProvider
                        );
                    }
                }

                // 3. Restaurer le stock
                foreach ($order->items as $item) {
                    if ($item->product && $item->product->stock !== null) {
                        $item->product->increment('stock', $item->quantity);
                    }
                }

                // 4. Notification au client
                $client = $order->user;
                if ($client) {
                    $this->fcmService->sendToUser(
                        $client,
                        'Commande refusée',
                        "Votre commande #{$order->order_number} a été refusée. Vos fonds ont été débloqués.",
                        [
                            'type' => 'order_rejected',
                            'order_id' => (string) $order->id,
                            'order_number' => $order->order_number,
                            'reason' => $cancelReason,
                        ]
                    );
                }
            });

            return response()->json([
                'success' => true,
                'message' => 'Commande refusée. Fonds du client débloqués.',
            ]);

        } catch (\Exception $e) {
            Log::error("[VendorOrderController] Erreur rejet commande: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Assign a delivery person to an order and notify them
     */
    public function assignDelivery(Request $request, $id)
    {
        $request->validate([
            'delivery_person_id' => 'required|exists:users,id',
        ]);

        $user = $request->user();
        $order = $this->getVendorOrder($user, $id);

        if (!in_array($order->status, ['confirmed', 'preparing'])) {
            return response()->json(['success' => false, 'message' => 'La commande doit être confirmée avant d\'assigner un livreur'], 422);
        }

        // Verify the delivery person has livreur role
        $deliveryPerson = User::where('id', $request->delivery_person_id)
            ->where('role', 'livreur')
            ->first();

        if (!$deliveryPerson) {
            return response()->json(['success' => false, 'message' => 'Livreur non trouvé'], 404);
        }

        $order->update([
            'delivery_person_id' => $deliveryPerson->id,
            'status' => 'preparing',
        ]);

        // Notification au livreur
        $this->fcmService->sendToUser(
            $deliveryPerson,
            'Nouvelle livraison assignée',
            "Commande #{$order->order_number} — Livraison vers {$order->delivery_address}. Frais: " . number_format($order->delivery_fee, 0, ',', ' ') . " FCFA",
            [
                'type' => 'delivery_assigned',
                'order_id' => (string) $order->id,
                'order_number' => $order->order_number,
                'delivery_fee' => (string) $order->delivery_fee,
                'delivery_address' => $order->delivery_address,
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Livreur assigné et notifié',
            'delivery_person' => [
                'id' => $deliveryPerson->id,
                'name' => $deliveryPerson->name,
                'phone' => $deliveryPerson->phone,
            ],
        ]);
    }

    /**
     * Get available delivery persons
     */
    public function availableDeliveryPersons(Request $request)
    {
        $deliveryPersons = User::where('role', 'livreur')
            ->select('id', 'first_name', 'last_name', 'phone', 'address', 'avatar', 'latitude', 'longitude')
            ->get();

        return response()->json([
            'success' => true,
            'delivery_persons' => $deliveryPersons->map(fn($dp) => [
                'id' => $dp->id,
                'name' => $dp->name,
                'phone' => $dp->phone,
                'address' => $dp->address,
                'avatar' => $dp->avatar,
                'latitude' => $dp->latitude,
                'longitude' => $dp->longitude,
            ]),
        ]);
    }

    /**
     * Notifie les livreurs de la delivery company assignée à la commande.
     * Envoie une notification à tous les livreurs synchronisés et actifs de cette company.
     */
    private function notifyDeliveryCompany(Order $order): void
    {
        if (!$order->delivery_company_id) return;

        // Récupérer tous les livreurs actifs synchronisés à cette company
        $activeSyncs = DelivererCodeSync::where('company_id', $order->delivery_company_id)
            ->active()
            ->with('user')
            ->get();

        foreach ($activeSyncs as $sync) {
            if ($sync->user) {
                $this->fcmService->sendToUser(
                    $sync->user,
                    'Nouvelle livraison disponible',
                    "Commande #{$order->order_number} — Livraison vers {$order->delivery_address}. Commission: " . number_format($order->delivery_fee, 0, ',', ' ') . " FCFA",
                    [
                        'type' => 'new_delivery_request',
                        'order_id' => (string) $order->id,
                        'order_number' => $order->order_number,
                        'delivery_fee' => (string) $order->delivery_fee,
                        'delivery_address' => $order->delivery_address,
                        'delivery_latitude' => (string) ($order->delivery_latitude ?? ''),
                        'delivery_longitude' => (string) ($order->delivery_longitude ?? ''),
                    ]
                );
            }
        }

        Log::info("[VendorOrderController] Notified {$activeSyncs->count()} deliverers for order #{$order->order_number}");
    }

    /**
     * Helper to get order that belongs to this vendor
     */
    private function getVendorOrder($user, $orderId)
    {
        $orderIds = OrderItem::where('seller_id', $user->id)->pluck('order_id')->unique();
        return Order::with(['items.product.primaryImage', 'user', 'deliveryPerson'])
            ->whereIn('id', $orderIds)
            ->findOrFail($orderId);
    }

    /**
     * Format vendor order
     */
    private function formatVendorOrder($order, $vendorId): array
    {
        return [
            'id' => $order->id,
            'order_number' => $order->order_number,
            'status' => $order->status,
            'total' => (float) $order->total,
            'subtotal' => (float) $order->subtotal,
            'delivery_fee' => (float) $order->delivery_fee,
            'delivery_address' => $order->delivery_address,
            'payment_method' => $order->payment_method,
            'payment_status' => $order->payment_status,
            'customer' => $order->user ? [
                'id' => $order->user->id,
                'name' => $order->user->name,
                'phone' => $order->user->phone,
            ] : null,
            'delivery_person' => $order->deliveryPerson ? [
                'id' => $order->deliveryPerson->id,
                'name' => $order->deliveryPerson->name,
                'phone' => $order->deliveryPerson->phone,
            ] : null,
            'items' => $order->items->map(fn($item) => [
                'id' => $item->id,
                'product_id' => $item->product_id,
                'product_name' => $item->product->name ?? 'Produit',
                'product_image' => $item->product?->primaryImage ? asset('storage/' . $item->product->primaryImage->image_path) : null,
                'quantity' => $item->quantity,
                'unit_price' => (float) $item->unit_price,
                'total_price' => (float) $item->total_price,
            ]),
            'created_at' => $order->created_at->toIso8601String(),
            'confirmed_at' => $order->confirmed_at?->toIso8601String(),
        ];
    }
}