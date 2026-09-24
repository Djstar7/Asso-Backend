<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Models\DelivererCodeSync;
use App\Services\WalletService;
use App\Services\OrderService;
use App\Services\FirebaseMessagingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class VendorOrderController extends Controller
{
    protected WalletService $walletService;
    protected FirebaseMessagingService $fcmService;
    protected OrderService $orderService;

    public function __construct(WalletService $walletService, FirebaseMessagingService $fcmService, OrderService $orderService)
    {
        $this->walletService = $walletService;
        $this->fcmService = $fcmService;
        $this->orderService = $orderService;
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
        }, 'user', 'deliveryPerson', 'deliveryCompany', 'trackingEvents'])
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
     * Flow (ENCAISSEMENT DIRECT — sans escrow) :
     * 1. Passe la commande en "confirmed"
     * 2. Prélève définitivement le client (mode wallet) et crédite IMMÉDIATEMENT le
     *    vendeur, le livreur et ASSO — fonds disponibles tout de suite (plus de blocage)
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

        // Le paiement doit être acquis avant toute validation. En mode wallet les fonds
        // sont bloqués dès la création (payment_status = 'paid') ; en kpay_direct la
        // commande reste 'pending'/'pending' tant que le PayIn Mobile Money n'a pas abouti.
        // Sans ce garde-fou, le vendeur crédite un escrow sur de l'argent jamais encaissé.
        if ($order->payment_status !== 'paid') {
            return response()->json(['success' => false, 'message' => "Le paiement de cette commande n'est pas encore confirmé."], 422);
        }

        try {
            // Confirmation + règlement (vendeur, livreur, ASSO) + acheteur prévenu.
            $this->orderService->confirmBySeller($order, $vendor, 'vendor', $vendor->id);

            // Au livreur (via la delivery company assignée) — livraison urbaine
            // uniquement : un transporteur reçoit le colis en agence. Vérification
            // de l'acceptation de la course après 5 minutes.
            if (!$order->isCarrierDelivery()) {
                $this->notifyDeliveryCompany($order);
                \App\Jobs\CheckDeliveryAcceptanceJob::dispatch($order->id)
                    ->delay(now()->addMinutes(5));
            }

            return response()->json([
                'success' => true,
                'message' => 'Commande validée. Fonds crédités et disponibles immédiatement.',
                'order' => $this->formatVendorOrder(
                    $order->fresh(['items.product.primaryImage', 'user', 'deliveryPerson', 'deliveryCompany', 'trackingEvents']),
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
            // Annulation + remboursement + stock restauré + acheteur prévenu.
            $refunded = $this->orderService->rejectBySeller(
                $order,
                $request->reason ?? 'Refusée par le vendeur',
                'vendor',
                $request->user()->id,
            );

            return response()->json([
                'success' => true,
                'message' => $refunded > 0
                    ? 'Commande refusée. Le client a été remboursé sur son Wallet ASSO.'
                    : 'Commande refusée.',
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
        if ($order->isCarrierDelivery()) {
            return response()->json(['success' => false, 'message' => 'Cette commande part par transporteur : remettez le colis en agence et saisissez son numéro de suivi.'], 422);
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
        app(\App\Services\OrderTrackingService::class)->record($order, 'preparing', null, "Livreur : {$deliveryPerson->name}", 'vendor', $user->id);

        // Notification au livreur
        $this->fcmService->sendToUser(
            $deliveryPerson,
            'Nouvelle livraison assignée',
            "Commande #{$order->order_number} — Livraison vers {$order->delivery_address}" . ($order->delivery_address_details ? " ({$order->delivery_address_details})" : '') . ". Contact: {$order->customer_phone}. Frais: " . number_format($order->delivery_fee, 0, ',', ' ') . " FCFA",
            [
                'type' => 'delivery_assigned',
                'order_id' => (string) $order->id,
                'order_number' => $order->order_number,
                'delivery_fee' => (string) $order->delivery_fee,
                'delivery_address' => $order->delivery_address,
                'delivery_address_details' => $order->delivery_address_details,
                'customer_phone' => $order->customer_phone,
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
     * Transporteur (SOLEX, DHL, FedEx) : le vendeur a déposé le colis en agence et
     * saisit le numéro de suivi du transporteur. La commande passe « expédiée ».
     *
     * POST /vendor/orders/{id}/hand-to-carrier  { carrier_tracking_number, location?, note? }
     */
    public function handToCarrier(Request $request, $id)
    {
        $validated = $request->validate([
            'carrier_tracking_number' => 'required|string|max:100',
            'location' => 'nullable|string|max:150',
            'note' => 'nullable|string|max:500',
        ]);

        $vendor = $request->user();
        $order = $this->getVendorOrder($vendor, $id);

        if (!$order->isCarrierDelivery()) {
            return response()->json(['success' => false, 'message' => 'Cette commande est livrée par un livreur urbain.'], 422);
        }
        if (!in_array($order->status, ['confirmed', 'preparing'])) {
            return response()->json(['success' => false, 'message' => 'La commande doit être validée avant la remise au transporteur.'], 422);
        }

        DB::transaction(function () use ($order, $validated, $vendor) {
            $order->update([
                'status' => 'shipped',
                'shipped_at' => now(),
                'carrier_tracking_number' => trim($validated['carrier_tracking_number']),
            ]);
            app(\App\Services\OrderTrackingService::class)->record(
                $order,
                'handed_to_carrier',
                $validated['location'] ?? null,
                trim("N° de suivi {$order->carrier_tracking_number}. " . ($validated['note'] ?? '')),
                'vendor',
                $vendor->id,
                notifyBuyer: true,
            );
        });

        return response()->json([
            'success' => true,
            'message' => 'Colis remis au transporteur. L\'acheteur a été prévenu.',
            'order' => $this->formatVendorOrder($order->fresh(['items.product.primaryImage', 'user', 'deliveryPerson', 'deliveryCompany', 'trackingEvents']), $vendor->id),
        ]);
    }

    /**
     * Étape d'acheminement transporteur (en transit, dédouanement, arrivé, disponible
     * en agence). L'acheteur est notifié à chaque étape.
     *
     * POST /vendor/orders/{id}/tracking  { step, location?, note? }
     */
    public function addTrackingStep(Request $request, $id)
    {
        $validated = $request->validate([
            'step' => 'required|string',
            'location' => 'nullable|string|max:150',
            'note' => 'nullable|string|max:500',
        ]);

        $vendor = $request->user();
        $order = $this->getVendorOrder($vendor, $id);

        // Import en gros : « arrivé à l'entrepôt de Douala » en plus des étapes transporteur.
        if (!in_array($validated['step'], \App\Services\OrderTrackingService::carrierUpdateSteps($order), true)) {
            throw \Illuminate\Validation\ValidationException::withMessages(['step' => 'Étape de suivi inconnue.']);
        }

        if (!$order->isCarrierDelivery() || $order->status !== 'shipped') {
            return response()->json(['success' => false, 'message' => 'Le suivi transporteur commence après la remise du colis.'], 422);
        }

        app(\App\Services\OrderTrackingService::class)->record(
            $order, $validated['step'], $validated['location'] ?? null, $validated['note'] ?? null,
            'vendor', $vendor->id, notifyBuyer: true,
        );

        return response()->json([
            'success' => true,
            'message' => 'Étape ajoutée. L\'acheteur a été prévenu.',
            'order' => $this->formatVendorOrder($order->fresh(['items.product.primaryImage', 'user', 'deliveryPerson', 'deliveryCompany', 'trackingEvents']), $vendor->id),
        ]);
    }

    /**
     * Get available delivery persons for a specific order's delivery company.
     * Returns only deliverers synced with the company chosen by the client.
     *
     * GET /vendor/orders/delivery-persons?order_id=X
     * GET /vendor/orders/delivery-persons?company_id=X
     */
    public function availableDeliveryPersons(Request $request)
    {
        $companyId = $request->input('company_id');

        // Si order_id fourni, récupérer la company depuis la commande
        if (!$companyId && $request->input('order_id')) {
            $order = Order::find($request->input('order_id'));
            if ($order) {
                $companyId = $order->delivery_company_id;
            }
        }

        if (!$companyId) {
            return response()->json([
                'success' => false,
                'message' => 'Veuillez fournir order_id ou company_id',
            ], 422);
        }

        // Récupérer la company
        $company = \App\Models\DelivererCompany::find($companyId);
        if (!$company) {
            return response()->json([
                'success' => false,
                'message' => 'Entreprise de livraison non trouvée',
            ], 404);
        }

        // Récupérer les livreurs synchronisés et actifs de cette company
        $syncs = DelivererCodeSync::where('company_id', $companyId)
            ->active()
            ->with('user')
            ->get();

        $deliveryPersons = $syncs
            ->filter(fn($sync) => $sync->user !== null)
            ->map(fn($sync) => [
                'id' => $sync->user->id,
                'name' => $sync->user->first_name . ' ' . $sync->user->last_name,
                'phone' => $sync->user->phone,
                'address' => $sync->user->address,
                'avatar' => $sync->user->avatar ? media_url($sync->user->avatar) : null,
                'latitude' => $sync->user->latitude,
                'longitude' => $sync->user->longitude,
                'synced_at' => $sync->synced_at?->toIso8601String(),
            ])
            ->values();

        return response()->json([
            'success' => true,
            'company' => [
                'id' => $company->id,
                'name' => $company->name,
                'phone' => $company->phone,
                'logo' => $company->logo ? media_url($company->logo) : null,
            ],
            'delivery_persons' => $deliveryPersons,
            'total' => $deliveryPersons->count(),
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
        return Order::with(['items.product.primaryImage', 'user', 'deliveryPerson', 'deliveryCompany', 'trackingEvents'])
            ->whereIn('id', $orderIds)
            ->findOrFail($orderId);
    }

    /**
     * Commandes encore en cours (non livrées, non annulées) : la boutique ne peut
     * pas déménager tant qu'elles ne sont pas terminées.
     */
    public function checkActiveOrders(Request $request)
    {
        $count = Order::whereIn('id', OrderItem::where('seller_id', $request->user()->id)->select('order_id'))
            ->whereIn('status', ['pending', 'confirmed', 'preparing', 'shipped'])
            ->count();

        return response()->json([
            'success' => true,
            'has_active_orders' => $count > 0,
            'active_orders_count' => $count,
        ]);
    }

    /**
     * Détail d'une commande pour le vendeur (uniquement ses articles).
     */
    public function show(Request $request, $id)
    {
        $user = $request->user();
        $order = Order::with([
            'items' => fn ($q) => $q->where('seller_id', $user->id)->with('product.primaryImage'),
            'user', 'deliveryPerson', 'deliveryCompany', 'trackingEvents',
        ])->whereIn('id', OrderItem::where('seller_id', $user->id)->select('order_id'))
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'order' => $this->formatVendorOrder($order, $user->id),
        ]);
    }

    /**
     * Format vendor order : tout ce qu'il faut pour préparer et suivre la commande.
     */
    private function formatVendorOrder($order, $vendorId): array
    {
        [$city] = \App\Support\LocationFormatter::parse($order->delivery_address);

        return [
            'id' => $order->id,
            'order_number' => $order->order_number,
            'status' => $order->status,
            'is_wholesale' => (bool) $order->is_wholesale,
            'total' => (float) $order->total,
            'subtotal' => (float) $order->subtotal,
            'delivery_fee' => (float) $order->delivery_fee,
            // Montant dû au vendeur : SES prix (hors majoration ASSO payée par le client).
            'vendor_amount' => (float) $order->items->sum(fn ($i) => $i->seller_total_price ?? $i->total_price),
            'delivery_address' => $order->delivery_address,
            'delivery_address_details' => $order->delivery_address_details,
            'delivery_latitude' => $order->delivery_latitude !== null ? (float) $order->delivery_latitude : null,
            'delivery_longitude' => $order->delivery_longitude !== null ? (float) $order->delivery_longitude : null,
            'city' => $city,
            'notes' => $order->notes,
            'payment_method' => $order->payment_method,
            'payment_status' => $order->payment_status,
            'tracking_number' => $order->tracking_number,
            'cancel_reason' => $order->cancel_reason,
            'customer' => $order->user ? [
                'id' => $order->user->id,
                'name' => $order->user->name,
                'phone' => $order->user->phone,
            ] : null,
            // Numéro saisi pour la livraison (souvent différent de celui du compte).
            'customer_phone' => $order->customer_phone ?: $order->user?->phone,
            'delivery_company' => $order->deliveryCompany ? [
                'id' => $order->deliveryCompany->id,
                'name' => $order->deliveryCompany->name,
            ] : null,
            'delivery' => \App\Support\DeliveryPresenter::forOrder($order),
            'delivery_person_id' => $order->delivery_person_id,
            'delivery_person' => $order->deliveryPerson ? [
                'id' => $order->deliveryPerson->id,
                'name' => $order->deliveryPerson->name,
                'phone' => $order->deliveryPerson->phone,
            ] : null,
            'items' => $order->items->map(fn ($item) => [
                'id' => $item->id,
                'product_id' => $item->product_id,
                'product_name' => $item->product->name ?? 'Produit',
                'product_image' => $item->product?->primaryImage ? media_url($item->product->primaryImage->image_path) : null,
                'quantity' => $item->quantity,
                // Prix du vendeur (ce qu'il touche), pas le prix public majoré.
                'unit_price' => (float) ($item->seller_unit_price ?? $item->unit_price),
                'total_price' => (float) ($item->seller_total_price ?? $item->total_price),
                // Choix du client à préparer : couleur, taille, pointure…
                'variant_id' => $item->product_variant_id,
                'variant_attributes' => $item->variant_attributes,
                'variant_label' => $item->variant_attributes
                    ? collect($item->variant_attributes)->map(fn ($v, $k) => "$k : $v")->implode(' · ')
                    : null,
                'tier_label' => $item->tier_label,
            ])->values(),
            'created_at' => $order->created_at->toIso8601String(),
            'confirmed_at' => $order->confirmed_at?->toIso8601String(),
            'shipped_at' => $order->shipped_at?->toIso8601String(),
            'delivered_at' => $order->delivered_at?->toIso8601String(),
            'cancelled_at' => $order->cancelled_at?->toIso8601String(),
        ];
    }
}
