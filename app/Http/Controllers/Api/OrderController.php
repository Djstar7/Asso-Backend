<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Services\OrderService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller
{
    protected OrderService $orderService;

    public function __construct(OrderService $orderService)
    {
        $this->orderService = $orderService;
    }

    /**
     * List user orders
     */
    public function index(Request $request)
    {
        $query = Order::with(['items.product.primaryImage', 'items.product.images', 'items.seller', 'deliveryPerson', 'deliveryCompany', 'rating', 'trackingEvents'])
            ->where('user_id', $request->user()->id);

        // Masquer les commandes payées par un rail DIRECT (KPay/PayPal/carte) dont le
        // paiement n'a PAS encore abouti : elles ne doivent apparaître qu'une fois payées.
        // (Les commandes wallet sont 'paid' d'emblée ; les directes le deviennent au succès.)
        $query->where(function ($q) {
            $q->where('payment_status', '!=', 'pending')
              ->orWhereNotIn('payment_method', ['kpay_direct', 'paypal_direct', 'stripe_direct']);
        });

        // Filter by status
        if ($request->has('status') && $request->status) {
            $query->where('status', $request->status);
        }

        $orders = $query->orderBy('created_at', 'desc')
            ->paginate($request->get('per_page', 20));

        return response()->json([
            'success' => true,
            'orders' => $orders->getCollection()->map(fn($order) => $this->formatOrder($order)),
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
     * Show single order
     */
    public function show(Request $request, $id)
    {
        $order = Order::with(['items.product.primaryImage', 'items.product.images', 'items.seller', 'deliveryPerson', 'deliveryCompany', 'rating', 'trackingEvents'])
            ->where('user_id', $request->user()->id)
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'order' => $this->formatOrder($order, true),
        ]);
    }

    /**
     * Create a new order with escrow (wallet lock)
     *
     * POST /api/v1/orders
     * Body: items[], delivery_company_id, delivery_zone_id, wallet_provider,
     *       delivery_address, delivery_latitude, delivery_longitude, notes
     */
    public function store(Request $request)
    {
        $request->validate([
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.variant_id' => 'nullable|exists:product_variants,id',
            'items.*.quantity' => 'required|integer|min:1',
            'delivery_company_id' => 'required|exists:deliverer_companies,id',
            // Livraison urbaine : zone ; interurbain / international : trajet transporteur.
            'delivery_zone_id' => 'required_without_all:delivery_route_id,delivery_grid_id|nullable|exists:delivery_zones,id',
            'delivery_route_id' => 'nullable|exists:delivery_routes,id',
            // Grille urbaine zone à zone : véhicule et quartier choisis par l'acheteur.
            'delivery_grid_id' => 'nullable|exists:delivery_city_grids,id',
            'delivery_vehicle' => 'nullable|string|max:30',
            'delivery_quarter' => 'nullable|string|max:120',
            'delivery_city' => 'nullable|string|max:120',
            'delivery_country' => 'nullable|string|max:60',
            // Mode de paiement : 'wallet' (escrow solde) | 'kpay_direct' (PayIn KPay)
            //                  | 'stripe_direct' (carte bancaire NATIVE, Payment Sheet)
            'payment_mode' => 'nullable|in:wallet,kpay_direct,stripe_direct',
            'wallet_provider' => 'required_if:payment_mode,wallet|in:kpay',
            // Requis en mode kpay_direct
            'provider' => 'required_if:payment_mode,kpay_direct|string',
            'phone_number' => 'required_if:payment_mode,kpay_direct|string',
            'delivery_address' => 'nullable|string',
            'delivery_address_details' => 'nullable|string|max:500',
            'customer_phone' => 'required|string|max:30',
            'delivery_latitude' => 'nullable|numeric',
            'delivery_longitude' => 'nullable|numeric',
            'notes' => 'nullable|string',
        ]);

        try {
            $paymentMode = $request->input('payment_mode', 'wallet');

            // Garde-fou : le paiement par carte (Stripe natif) n'est proposé que s'il est
            // réellement fonctionnel (clés configurées + activé). Sinon la commande est
            // BLOQUÉE immédiatement, sans rien créer ni décrémenter de stock.
            if ($paymentMode === 'stripe_direct'
                && !\App\Services\PaymentMethodService::isEnabled('stripe')) {
                return response()->json([
                    'success' => false,
                    'message' => "Le paiement par carte bancaire (Stripe) n'est pas disponible pour le moment. Veuillez choisir un autre moyen de paiement.",
                ], 422);
            }

            $order = $this->orderService->createOrder(
                client: $request->user(),
                items: $request->items,
                deliveryCompanyId: (int) $request->delivery_company_id,
                deliveryZoneId: $request->delivery_zone_id ? (int) $request->delivery_zone_id : null,
                walletProvider: $request->input('wallet_provider', 'kpay'),
                deliveryAddress: $request->delivery_address,
                deliveryAddressDetails: $request->delivery_address_details,
                customerPhone: $request->customer_phone,
                deliveryLatitude: $request->delivery_latitude,
                deliveryLongitude: $request->delivery_longitude,
                notes: $request->notes,
                paymentMode: $paymentMode,
                kpayProvider: $request->input('provider'),
                kpayPhone: $request->input('phone_number'),
                deliveryRouteId: $request->delivery_route_id ? (int) $request->delivery_route_id : null,
                deliveryCity: $request->input('delivery_city'),
                deliveryCountry: $request->input('delivery_country'),
                deliveryGridId: $request->delivery_grid_id ? (int) $request->delivery_grid_id : null,
                deliveryVehicle: $request->input('delivery_vehicle'),
                deliveryQuarter: $request->input('delivery_quarter'),
            );

            return response()->json([
                'success' => true,
                'message' => match ($paymentMode) {
                    'kpay_direct' => 'Commande créée. Validez le paiement sur votre téléphone (USSD).',
                    'stripe_direct' => 'Commande créée. Finalisez le paiement par carte.',
                    default => 'Commande créée avec succès. Fonds bloqués en attente de validation.',
                },
                'order' => $this->formatOrder($order),
                // Pour le polling du statut de paiement (modes directs)
                'payment_reference' => $order->payment_reference,
                'order_id' => $order->id,
                // Carte native (stripe_direct) : le mobile confirme via la Payment Sheet
                // (SDK flutter_stripe) avec ces éléments, puis poll payment-status.
                'client_secret' => $paymentMode === 'stripe_direct' ? ($order->client_secret ?? null) : null,
                'payment_intent_id' => $paymentMode === 'stripe_direct' ? ($order->payment_intent_id ?? null) : null,
                'publishable_key' => $paymentMode === 'stripe_direct' ? ($order->stripe_publishable_key ?? null) : null,
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Statut de paiement d'une commande (mode kpay_direct).
     * Re-vérifie chez KPay et confirme la commande si le paiement est complété.
     * GET /v1/orders/{id}/payment-status  — utilisé par le polling mobile.
     */
    public function paymentStatus(Request $request, $id)
    {
        $order = Order::where('user_id', $request->user()->id)->findOrFail($id);

        if ($order->payment_status === 'pending'
            && $order->payment_method === 'kpay_direct'
            && $order->payment_reference) {
            $result = (new \App\Services\KPayService())->checkPaymentStatus($order->payment_reference);
            $status = strtoupper($result['status'] ?? 'UNKNOWN');

            if (in_array($status, ['SUCCESS', 'SUCCESSFUL', 'COMPLETED'])) {
                $this->orderService->confirmKpayOrderPayment($order);
                $order->refresh();
            } elseif (in_array($status, ['FAILED', 'FAILURE', 'ERROR', 'REJECTED', 'CANCELLED', 'CANCELED'])) {
                $this->orderService->failKpayOrderPayment($order);
                $order->refresh();
            }
        } elseif ($order->payment_status === 'pending'
            && $order->payment_method === 'stripe_direct'
            && $order->payment_reference) {
            // Confirmation carte Stripe côté serveur (idempotent) via le PaymentIntent.
            $this->orderService->syncStripeOrder($order);
            $order->refresh();
        }

        return response()->json([
            'success' => true,
            'data' => [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'payment_status' => $order->payment_status, // pending | paid | failed
                'status' => $order->status,
            ],
        ]);
    }

    /**
     * Cancel an order and unlock escrowed funds
     */
    public function cancel(Request $request, $id)
    {
        $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        $order = Order::with('items.product')
            ->where('user_id', $request->user()->id)
            ->whereIn('status', ['pending'])
            ->findOrFail($id);

        try {
            $refunded = 0.0;
            DB::transaction(function () use ($request, $order, &$refunded) {
                // Verrou + re-contrôle : le vendeur a pu valider entre-temps.
                $locked = Order::whereKey($order->id)->lockForUpdate()->first();
                if (!$locked || $locked->status !== 'pending') {
                    throw new \Exception('Cette commande a déjà été prise en charge par le vendeur et ne peut plus être annulée.');
                }

                // Remboursement : déblocage de l'escrow (wallet) ou crédit du Wallet ASSO
                // (Mobile Money / carte déjà encaissés). Idempotent.
                $refunded = app(\App\Services\OrderService::class)->refundBuyer(
                    $order,
                    "Remboursement commande #{$order->order_number} — annulée",
                    ['cancel_reason' => $request->reason]
                );

                // Restaurer le stock décrémenté à la création
                foreach ($order->items as $item) {
                    $item->restoreStock();
                }

                $order->update([
                    'status' => 'cancelled',
                    'cancel_reason' => $request->reason,
                    'cancelled_at' => now(),
                ]);

                app(\App\Services\OrderTrackingService::class)->record(
                    $order, 'cancelled', null, $request->reason, 'buyer', $request->user()->id,
                );
            });

            $order->notifySellers(
                'Commande annulée par le client',
                "La commande #{$order->order_number} a été annulée" . ($request->reason ? " : {$request->reason}" : '.') . ' Le stock a été remis en vente.',
                ['type' => 'order_cancelled_vendor', 'cancel_reason' => $request->reason],
            );

            return response()->json([
                'success' => true,
                'message' => $refunded > 0
                    ? 'Commande annulée. ' . number_format($refunded, 0, ',', ' ') . ' FCFA sont disponibles sur votre Wallet ASSO.'
                    : 'Commande annulée.',
                'refunded_amount' => $refunded,
                'order' => $this->formatOrder($order->fresh()),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * L'acheteur confirme avoir reçu son colis (transporteur : retrait en agence ou
     * livraison DHL/FedEx). Les livraisons urbaines se clôturent par le code à 6 chiffres.
     *
     * POST /api/v1/orders/{id}/confirm-reception
     */
    public function confirmReception(Request $request, $id)
    {
        $order = Order::where('user_id', $request->user()->id)
            ->where('delivery_mode', Order::DELIVERY_CARRIER)
            ->whereNull('delivery_zone_id') // à domicile : clôture par le code du coursier
            ->whereNull('delivery_city_grid_id')
            ->where('status', 'shipped')
            ->findOrFail($id);

        DB::transaction(function () use ($order, $request) {
            $order->update([
                'status' => 'delivered',
                'delivered_at' => now(),
                'confirmed_by_client_at' => now(),
                'confirmation_code' => null,
            ]);
            app(\App\Services\OrderTrackingService::class)->record(
                $order, 'delivered', null, "Réception confirmée par l'acheteur", 'buyer', $request->user()->id,
            );
        });

        $order->notifySellers(
            'Colis reçu',
            "L'acheteur a confirmé la réception de la commande #{$order->order_number}.",
            ['type' => 'order_delivered_vendor'],
        );

        return response()->json([
            'success' => true,
            'message' => 'Réception confirmée. Merci !',
            'order' => $this->formatOrder($order->fresh(['items.product.primaryImage', 'items.seller', 'deliveryCompany', 'trackingEvents']), true),
        ]);
    }

    /**
     * Rate a delivered order
     *
     * POST /api/v1/orders/{id}/rate
     */
    public function rate(Request $request, $id)
    {
        $request->validate([
            'rating' => 'required|integer|min:1|max:5',
            'comment' => 'nullable|string|max:1000',
        ], [
            'rating.required' => 'Merci d’attribuer une note.',
            'rating.integer' => 'La note doit être un nombre entier.',
            'rating.min' => 'La note doit être comprise entre 1 et 5 étoiles.',
            'rating.max' => 'La note doit être comprise entre 1 et 5 étoiles.',
            'comment.max' => 'Votre commentaire ne doit pas dépasser 1000 caractères.',
        ]);

        // Diagnostic précis : sans cela une commande déjà notée renvoie une
        // exception technique « No query results for model [Order] ».
        $order = Order::where('user_id', $request->user()->id)->find($id);

        if (!$order) {
            return response()->json([
                'success' => false,
                'message' => 'Commande introuvable.',
            ], 404);
        }

        if ($order->status !== 'delivered') {
            return response()->json([
                'success' => false,
                'message' => 'Vous pourrez noter cette commande une fois qu’elle sera livrée.',
            ], 422);
        }

        if ($order->rated_at !== null) {
            return response()->json([
                'success' => false,
                'message' => 'Vous avez déjà noté cette commande.',
            ], 422);
        }

        try {
            DB::transaction(function () use ($request, $order) {
                \App\Models\OrderRating::create([
                    'order_id' => $order->id,
                    'user_id' => $request->user()->id,
                    'rating' => $request->rating,
                    'comment' => $request->comment,
                ]);

                $order->update(['rated_at' => now()]);

                // La note de la commande alimente la réputation des produits (et
                // donc celle de la boutique, calculée sur product_reviews) : sans
                // cela une note client n'aurait aucun effet visible.
                foreach ($order->items as $item) {
                    if (!$item->product_id) {
                        continue;
                    }

                    \App\Models\ProductReview::updateOrCreate(
                        [
                            'product_id' => $item->product_id,
                            'user_id' => $request->user()->id,
                        ],
                        [
                            'rating' => $request->rating,
                            'comment' => $request->comment,
                            'is_verified_purchase' => true,
                        ],
                    );
                }

                // FCM au vendeur
                $sellerIds = $order->items()->pluck('seller_id')->unique();
                $fcm = app(\App\Services\FirebaseMessagingService::class);

                foreach ($sellerIds as $sellerId) {
                    $seller = \App\Models\User::find($sellerId);
                    if ($seller) {
                        $stars = str_repeat('★', $request->rating) . str_repeat('☆', 5 - $request->rating);
                        $fcm->sendToUser(
                            $seller,
                            'Nouvelle note reçue',
                            "Commande #{$order->order_number} notée {$stars}",
                            [
                                'type' => 'order_rated',
                                'order_id' => (string) $order->id,
                                'rating' => (string) $request->rating,
                            ]
                        );
                    }
                }
            });

            return response()->json([
                'success' => true,
                'message' => 'Merci pour votre note !',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Format order for API response
     */
    private function formatOrder($order, $detailed = false): array
    {
        $data = [
            'id' => $order->id,
            'order_number' => $order->order_number,
            'status' => $order->status,
            'subtotal' => (float) $order->subtotal,
            'delivery_fee' => (float) $order->delivery_fee,
            // Import en gros : part du trajet jusqu'à Douala dans les frais de livraison.
            'import_shipping_fee' => $order->import_shipping_fee !== null ? (float) $order->import_shipping_fee : null,
            'total' => (float) $order->total,
            'formatted_total' => $order->formatted_total,
            'payment_method' => $order->payment_method,
            'payment_status' => $order->payment_status,
            'tracking_number' => $order->tracking_number,
            'delivery_address' => $order->delivery_address,
            'delivery_address_details' => $order->delivery_address_details,
            'customer_phone' => $order->customer_phone,
            'items' => $order->items->map(fn($item) => [
                'id' => $item->id,
                'product_id' => $item->product_id,
                'variant_id' => $item->product_variant_id,
                'variant_attributes' => $item->variant_attributes,
                'product_name' => $item->product->name ?? 'Produit supprimé',
                'product_image' => $item->product?->primaryImage
                    ? media_url($item->product->primaryImage->image_path)
                    : null,
                'quantity' => $item->quantity,
                'unit_price' => (float) $item->unit_price,
                'total_price' => (float) $item->total_price,
                // Vendeur de l'article : permet au mobile d'ouvrir une conversation
                // avec le vendeur depuis le suivi de commande.
                'seller_id' => $item->seller_id ?? $item->product?->user_id,
                'seller' => $item->seller
                    ? [
                        'id' => $item->seller->id,
                        'name' => $item->seller->name,
                        'avatar' => $item->seller->avatar,
                    ]
                    : ($item->product?->user
                        ? [
                            'id' => $item->product->user->id,
                            'name' => $item->product->user->name,
                            'avatar' => $item->product->user->avatar,
                        ]
                        : null),
            ]),
            'items_count' => $order->items->count(),
            'rated_at' => $order->rated_at?->toIso8601String(),
            'can_rate' => $order->status === 'delivered' && $order->rated_at === null,
            'rating' => $order->relationLoaded('rating') && $order->rating
                ? ['rating' => $order->rating->rating, 'comment' => $order->rating->comment]
                : null,
            'created_at' => $order->created_at->toIso8601String(),
        ];

        if ($order->deliveryPerson) {
            $data['delivery_person'] = [
                'id' => $order->deliveryPerson->id,
                'name' => $order->deliveryPerson->name,
                'phone' => $order->deliveryPerson->phone,
                'avatar' => $order->deliveryPerson->avatar,
            ];
        }

        if ($order->relationLoaded('deliveryCompany') && $order->deliveryCompany) {
            $data['delivery_company'] = [
                'id' => $order->deliveryCompany->id,
                'name' => $order->deliveryCompany->name,
                'phone' => $order->deliveryCompany->phone,
                'logo' => $order->deliveryCompany->logo ? media_url($order->deliveryCompany->logo) : null,
            ];
        }

        $data['delivery'] = \App\Support\DeliveryPresenter::forOrder($order);

        // Code de confirmation : livraison urbaine en cours uniquement (le transporteur
        // ne le demande pas, l'acheteur confirme lui-même la réception).
        if ($order->status === 'shipped' && (!$order->isCarrierDelivery() || $order->hasLastMileDelivery())) {
            $data['confirmation_code'] = $order->confirmation_code;
        }

        // Timestamps toujours inclus (nécessaires pour le tracking client)
        $data['confirmed_at'] = $order->confirmed_at?->toIso8601String();
        $data['shipped_at'] = $order->shipped_at?->toIso8601String();
        $data['delivered_at'] = $order->delivered_at?->toIso8601String();
        $data['cancelled_at'] = $order->cancelled_at?->toIso8601String();
        $data['cancel_reason'] = $order->cancel_reason;

        if ($detailed) {
            $data['notes'] = $order->notes;
            $data['confirmed_by_client_at'] = $order->confirmed_by_client_at?->toIso8601String();
            $data['confirmed_by_deliverer_at'] = $order->confirmed_by_deliverer_at?->toIso8601String();
        }

        return $data;
    }
}
