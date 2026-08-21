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
        $query = Order::with(['items.product.primaryImage', 'items.product.images', 'deliveryPerson', 'deliveryCompany', 'rating'])
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
        $order = Order::with(['items.product.primaryImage', 'items.product.images', 'items.seller', 'deliveryPerson', 'deliveryCompany'])
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
            'items.*.quantity' => 'required|integer|min:1',
            'delivery_company_id' => 'required|exists:deliverer_companies,id',
            'delivery_zone_id' => 'required|exists:delivery_zones,id',
            // Mode de paiement : 'wallet' (escrow solde) | 'kpay_direct' (PayIn KPay)
            //                  | 'paypal_direct' (checkout PayPal) | 'stripe_direct' (carte)
            'payment_mode' => 'nullable|in:wallet,kpay_direct,paypal_direct,stripe_direct',
            'wallet_provider' => 'required_if:payment_mode,wallet|in:kpay,paypal',
            // Requis en mode kpay_direct
            'provider' => 'required_if:payment_mode,kpay_direct|string',
            'phone_number' => 'required_if:payment_mode,kpay_direct|string',
            'delivery_address' => 'nullable|string',
            'delivery_latitude' => 'nullable|numeric',
            'delivery_longitude' => 'nullable|numeric',
            'notes' => 'nullable|string',
        ]);

        try {
            $paymentMode = $request->input('payment_mode', 'wallet');

            // Garde-fou : un paiement par redirection (PayPal / carte Stripe) n'est proposé
            // que s'il est réellement fonctionnel (clés configurées + activé). Sinon la
            // commande est BLOQUÉE immédiatement, sans rien créer ni décrémenter de stock.
            $railGuard = [
                'paypal_direct' => ['paypal', 'PayPal'],
                'stripe_direct' => ['stripe', 'par carte bancaire (Stripe)'],
            ];
            if (isset($railGuard[$paymentMode])
                && !\App\Services\PaymentMethodService::isEnabled($railGuard[$paymentMode][0])) {
                return response()->json([
                    'success' => false,
                    'message' => "Le paiement {$railGuard[$paymentMode][1]} n'est pas disponible pour le moment. Veuillez choisir un autre moyen de paiement.",
                ], 422);
            }

            $order = $this->orderService->createOrder(
                client: $request->user(),
                items: $request->items,
                deliveryCompanyId: (int) $request->delivery_company_id,
                deliveryZoneId: (int) $request->delivery_zone_id,
                walletProvider: $request->input('wallet_provider', 'kpay'),
                deliveryAddress: $request->delivery_address,
                deliveryLatitude: $request->delivery_latitude,
                deliveryLongitude: $request->delivery_longitude,
                notes: $request->notes,
                paymentMode: $paymentMode,
                kpayProvider: $request->input('provider'),
                kpayPhone: $request->input('phone_number'),
            );

            return response()->json([
                'success' => true,
                'message' => match ($paymentMode) {
                    'kpay_direct' => 'Commande créée. Validez le paiement sur votre téléphone (USSD).',
                    'paypal_direct' => 'Commande créée. Finalisez le paiement PayPal.',
                    'stripe_direct' => 'Commande créée. Finalisez le paiement par carte.',
                    default => 'Commande créée avec succès. Fonds bloqués en attente de validation.',
                },
                'order' => $this->formatOrder($order),
                // Pour le polling du statut de paiement (modes directs)
                'payment_reference' => $order->payment_reference,
                'order_id' => $order->id,
                // Modes redirect (PayPal / carte Stripe) : URL de checkout à ouvrir en WebView
                'approval_url' => in_array($paymentMode, ['paypal_direct', 'stripe_direct'])
                    ? ($order->approval_url ?? null) : null,
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
            && $order->payment_method === 'paypal_direct'
            && $order->payment_reference) {
            // Capture PayPal côté serveur (idempotent) sur la base de l'état de l'ordre PayPal.
            $this->orderService->syncPaypalOrder($order);
            $order->refresh();
        } elseif ($order->payment_status === 'pending'
            && $order->payment_method === 'stripe_direct'
            && $order->payment_reference) {
            // Confirmation carte Stripe côté serveur (idempotent) via la Checkout Session.
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
            DB::transaction(function () use ($request, $order) {
                $wallet = app(\App\Services\WalletService::class);

                // Rembourser le client selon le mode de paiement
                if ($order->payment_method === 'kpay_direct') {
                    // Paiement Mobile Money direct : rien de bloqué dans le wallet.
                    // Si déjà payé, rembourser en créditant le solde ; sinon rien à faire.
                    if ($order->payment_status === 'paid') {
                        $wallet->credit(
                            $request->user(),
                            (float) $order->total,
                            null,
                            "Remboursement commande #{$order->order_number} — annulée",
                            ['order_id' => $order->id, 'refund' => true, 'cancel_reason' => $request->reason],
                            'kpay'
                        );
                    }
                } else {
                    // Mode wallet : débloquer les fonds escrow.
                    $walletProvider = str_replace('wallet_', '', $order->payment_method);
                    if (in_array($walletProvider, ['kpay', 'paypal'])) {
                        $wallet->unlockFunds(
                            $request->user(),
                            (float) $order->total,
                            "Annulation commande #{$order->order_number}",
                            'order',
                            $order->id,
                            ['cancel_reason' => $request->reason],
                            $walletProvider
                        );
                    }
                }

                // Restaurer le stock décrémenté à la création
                foreach ($order->items as $item) {
                    if ($item->product && $item->product->stock !== null) {
                        $item->product->increment('stock', $item->quantity);
                    }
                }

                $order->update([
                    'status' => 'cancelled',
                    'cancel_reason' => $request->reason,
                    'cancelled_at' => now(),
                ]);
            });

            return response()->json([
                'success' => true,
                'message' => 'Commande annulée, fonds débloqués.',
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
     * Rate a delivered order
     *
     * POST /api/v1/orders/{id}/rate
     */
    public function rate(Request $request, $id)
    {
        $request->validate([
            'rating' => 'required|integer|min:1|max:5',
            'comment' => 'nullable|string|max:1000',
        ]);

        $order = Order::where('user_id', $request->user()->id)
            ->where('status', 'delivered')
            ->whereNull('rated_at')
            ->findOrFail($id);

        try {
            DB::transaction(function () use ($request, $order) {
                \App\Models\OrderRating::create([
                    'order_id' => $order->id,
                    'user_id' => $request->user()->id,
                    'rating' => $request->rating,
                    'comment' => $request->comment,
                ]);

                $order->update(['rated_at' => now()]);

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
            'total' => (float) $order->total,
            'formatted_total' => $order->formatted_total,
            'payment_method' => $order->payment_method,
            'payment_status' => $order->payment_status,
            'tracking_number' => $order->tracking_number,
            'delivery_address' => $order->delivery_address,
            'items' => $order->items->map(fn($item) => [
                'id' => $item->id,
                'product_id' => $item->product_id,
                'product_name' => $item->product->name ?? 'Produit supprimé',
                'product_image' => $item->product?->primaryImage
                    ? asset('storage/' . $item->product->primaryImage->image_path)
                    : null,
                'quantity' => $item->quantity,
                'unit_price' => (float) $item->unit_price,
                'total_price' => (float) $item->total_price,
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
                'logo' => $order->deliveryCompany->logo ? asset('storage/' . $order->deliveryCompany->logo) : null,
            ];
        }

        // Le confirmation_code est visible par le client quand la commande est en livraison (shipped)
        if (in_array($order->status, ['shipped'])) {
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
