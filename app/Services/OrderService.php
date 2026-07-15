<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Models\DelivererCompany;
use App\Models\DeliveryZone;
use App\Models\DeliveryPricelist;
use App\Services\WalletService;
use App\Services\FirebaseMessagingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OrderService
{
    protected WalletService $walletService;
    protected FirebaseMessagingService $fcmService;

    public function __construct(WalletService $walletService, FirebaseMessagingService $fcmService)
    {
        $this->walletService = $walletService;
        $this->fcmService = $fcmService;
    }

    /**
     * Récupère tous les partenaires de livraison avec le prix calculé pour un produit donné.
     * Le prix dépend du pricing_type de chaque zone (fixed, weight_category, volumetric_weight).
     */
    public function getDeliveryPartnersWithPricing(int $productId, ?float $latitude = null, ?float $longitude = null): array
    {
        $product = Product::findOrFail($productId);

        $companies = DelivererCompany::where('is_active', true)
            ->with(['deliveryZones' => function ($q) {
                $q->where('is_active', true)
                    ->whereNotNull('center_latitude')
                    ->whereNotNull('center_longitude')
                    ->with('activePricelist');
            }, 'user'])
            ->get();

        $partners = [];

        foreach ($companies as $company) {
            foreach ($company->deliveryZones as $zone) {
                $pricelist = $zone->activePricelist;
                if (!$pricelist) continue;

                // Calculer le prix selon le type de pricing de l'entreprise
                $price = $this->calculateDeliveryPrice($pricelist, $product, $latitude, $longitude, $zone);

                // Calculer la distance si les coordonnées du client sont fournies
                $distance = null;
                if ($latitude && $longitude && $zone->center_latitude && $zone->center_longitude) {
                    $distance = $this->calculateDistance(
                        $latitude, $longitude,
                        (float) $zone->center_latitude, (float) $zone->center_longitude
                    );
                }

                $partners[] = [
                    'company_id' => $company->id,
                    'company_name' => $company->name,
                    'company_phone' => $company->phone,
                    'company_email' => $company->email,
                    'company_description' => $company->description,
                    'company_logo' => $company->logo ? asset('storage/' . $company->logo) : null,
                    'zone_id' => $zone->id,
                    'zone_name' => $zone->name,
                    'zone_latitude' => (float) $zone->center_latitude,
                    'zone_longitude' => (float) $zone->center_longitude,
                    'pricing_type' => $pricelist->pricing_type,
                    'delivery_price' => $price,
                    'formatted_delivery_price' => number_format($price, 0, ',', ' ') . ' FCFA',
                    'distance_km' => $distance ? round($distance, 2) : null,
                    'deliverer' => $company->user ? [
                        'id' => $company->user->id,
                        'name' => $company->user->first_name . ' ' . $company->user->last_name,
                        'phone' => $company->user->phone,
                    ] : null,
                ];
            }
        }

        // Trier par distance si disponible, sinon par prix
        if ($latitude && $longitude) {
            usort($partners, fn($a, $b) => ($a['distance_km'] ?? PHP_FLOAT_MAX) <=> ($b['distance_km'] ?? PHP_FLOAT_MAX));
        } else {
            usort($partners, fn($a, $b) => $a['delivery_price'] <=> $b['delivery_price']);
        }

        return $partners;
    }

    /**
     * Calcule le prix de livraison selon le type de pricing choisi par l'entreprise.
     */
    private function calculateDeliveryPrice(
        DeliveryPricelist $pricelist,
        Product $product,
        ?float $clientLat,
        ?float $clientLng,
        DeliveryZone $zone
    ): float {
        switch ($pricelist->pricing_type) {
            case DeliveryPricelist::PRICING_TYPE_FIXED:
                return $pricelist->calculatePrice([]);

            case DeliveryPricelist::PRICING_TYPE_WEIGHT_CATEGORY:
                return $pricelist->calculatePrice([
                    'category' => $product->weight_category,
                ]);

            case DeliveryPricelist::PRICING_TYPE_VOLUMETRIC_WEIGHT:
                // Si le produit a des dimensions, utiliser le volumétrique
                // Sinon fallback sur le premier range
                return $pricelist->calculatePrice([
                    'length' => $product->length ?? 0,
                    'width' => $product->width ?? 0,
                    'height' => $product->height ?? 0,
                ]);

            default:
                return 0;
        }
    }

    /**
     * Crée une commande complète avec escrow.
     *
     * Flow :
     * 1. Valide le stock
     * 2. Calcule le prix de livraison via le pricelist du partenaire choisi
     * 3. Verrouille les fonds du client (escrow)
     * 4. Crée la commande en "pending"
     * 5. Envoie les notifications FCM (client + vendeur)
     */
    public function createOrder(
        User $client,
        array $items,
        int $deliveryCompanyId,
        int $deliveryZoneId,
        string $walletProvider,
        ?string $deliveryAddress = null,
        ?float $deliveryLatitude = null,
        ?float $deliveryLongitude = null,
        ?string $notes = null,
        string $paymentMode = 'wallet', // 'wallet' (escrow depuis solde) | 'kpay_direct' (PayIn KPay)
        ?string $kpayProvider = null,   // code opérateur KPay (mode kpay_direct)
        ?string $kpayPhone = null       // numéro Mobile Money (mode kpay_direct)
    ): Order {
        return DB::transaction(function () use (
            $client, $items, $deliveryCompanyId, $deliveryZoneId, $walletProvider,
            $deliveryAddress, $deliveryLatitude, $deliveryLongitude, $notes,
            $paymentMode, $kpayProvider, $kpayPhone
        ) {
            Log::info("[OrderService] === CREATION COMMANDE ===", [
                'client_id' => $client->id,
                'delivery_company_id' => $deliveryCompanyId,
                'delivery_zone_id' => $deliveryZoneId,
                'wallet_provider' => $walletProvider,
            ]);

            // 1. Valider les produits et calculer le sous-total
            $subtotal = 0;
            $orderItems = [];
            $sellers = [];

            foreach ($items as $item) {
                $product = Product::lockForUpdate()->findOrFail($item['product_id']);

                if (!in_array($product->status, ['published', 'active'])) {
                    throw new \Exception("Le produit '{$product->name}' n'est plus disponible.");
                }

                if ($product->stock !== null && $product->stock < $item['quantity']) {
                    throw new \Exception("Stock insuffisant pour '{$product->name}'. Disponible: {$product->stock}");
                }

                $price = (float) $product->price;
                $quantity = $item['quantity'];
                $totalPrice = $price * $quantity;
                $subtotal += $totalPrice;

                $orderItems[] = [
                    'product_id' => $product->id,
                    'seller_id' => $product->user_id,
                    'quantity' => $quantity,
                    'unit_price' => $price,
                    'total_price' => $totalPrice,
                ];

                // Collecter les vendeurs pour notification
                if (!in_array($product->user_id, $sellers)) {
                    $sellers[] = $product->user_id;
                }

                // Décrémenter le stock
                if ($product->stock !== null) {
                    $product->decrement('stock', $quantity);
                }
            }

            // 2. Calculer le prix de livraison
            $zone = DeliveryZone::where('id', $deliveryZoneId)
                ->where('deliverer_company_id', $deliveryCompanyId)
                ->where('is_active', true)
                ->with('activePricelist')
                ->firstOrFail();

            $pricelist = $zone->activePricelist;
            if (!$pricelist) {
                throw new \Exception("Aucun tarif de livraison configuré pour cette zone.");
            }

            // Utiliser le premier produit pour le calcul (ou le plus lourd)
            $firstProduct = Product::find($items[0]['product_id']);
            $deliveryFee = $this->calculateDeliveryPrice($pricelist, $firstProduct, $deliveryLatitude, $deliveryLongitude, $zone);

            $total = $subtotal + $deliveryFee;

            $isKpayDirect = $paymentMode === 'kpay_direct';

            // 3. Mode wallet : verrouiller les fonds du client (escrow depuis le solde).
            //    Mode kpay_direct : pas de verrou — le client paie via KPay (PayIn) ci-dessous.
            if (!$isKpayDirect) {
                $this->walletService->lockFunds(
                    $client,
                    $total,
                    "Escrow commande - En attente de validation vendeur",
                    'order',
                    null, // L'ID de l'order sera mis à jour après création
                    ['subtotal' => $subtotal, 'delivery_fee' => $deliveryFee],
                    $walletProvider
                );
            }

            // 4. Créer la commande
            $order = Order::create([
                'user_id' => $client->id,
                'status' => 'pending',
                'subtotal' => $subtotal,
                'delivery_fee' => $deliveryFee,
                'total' => $total,
                'delivery_address' => $deliveryAddress,
                'delivery_latitude' => $deliveryLatitude,
                'delivery_longitude' => $deliveryLongitude,
                'delivery_company_id' => $deliveryCompanyId,
                'delivery_zone_id' => $deliveryZoneId,
                'payment_method' => $isKpayDirect ? 'kpay_direct' : ('wallet_' . $walletProvider),
                // kpay_direct : en attente du paiement Mobile Money ; wallet : déjà payé (fonds bloqués)
                'payment_status' => $isKpayDirect ? 'pending' : 'paid',
                'notes' => $notes,
            ]);

            // Créer les items
            foreach ($orderItems as $itemData) {
                $order->items()->create($itemData);
            }

            // 4b. Mode kpay_direct : initier le PayIn KPay pour le total de la commande
            if ($isKpayDirect) {
                $kpayResult = app(\App\Services\KPayService::class)->initializePayment([
                    'amount' => (float) round($total),
                    'provider' => $kpayProvider,
                    'phone_number' => $kpayPhone,
                    'description' => "Commande {$order->order_number}",
                    'external_reference' => $order->order_number,
                ]);

                if (empty($kpayResult['success'])) {
                    // Rollback : la commande ne doit pas exister si le paiement n'a pu être initié
                    throw new \Exception($kpayResult['message'] ?? "Échec de l'initiation du paiement KPay.");
                }

                // payment_reference = id KPay (pay_xxx) pour le polling du statut
                $order->update(['payment_reference' => $kpayResult['id'] ?? null]);
            }

            // 5. Envoyer les notifications FCM

            // Notification client
            $this->fcmService->sendToUser(
                $client,
                'Commande en attente',
                "Votre commande #{$order->order_number} a été créée. En attente de validation du vendeur.",
                [
                    'type' => 'order_created',
                    'order_id' => (string) $order->id,
                    'order_number' => $order->order_number,
                    'total' => (string) $total,
                ]
            );

            // Notification vendeur(s)
            foreach ($sellers as $sellerId) {
                $seller = User::find($sellerId);
                if ($seller) {
                    $this->fcmService->sendToUser(
                        $seller,
                        'Nouvelle commande reçue',
                        "Vous avez reçu une nouvelle commande #{$order->order_number} de {$client->first_name} ({$order->formatted_total}).",
                        [
                            'type' => 'new_order_vendor',
                            'order_id' => (string) $order->id,
                            'order_number' => $order->order_number,
                            'total' => (string) $total,
                            'client_name' => $client->first_name . ' ' . $client->last_name,
                        ]
                    );
                }
            }

            $order->load(['items.product.primaryImage', 'items.product.images', 'deliveryCompany', 'deliveryZone']);

            Log::info("[OrderService] Commande créée avec succès", [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'total' => $total,
                'escrow_locked' => true,
            ]);

            return $order;
        });
    }

    /**
     * Confirme le paiement KPay direct d'une commande (idempotent).
     * Marque la commande payée/confirmée et crédite les gains (pending) des
     * vendeurs — les fonds sont séquestrés par la plateforme (compte KPay).
     */
    public function confirmKpayOrderPayment(Order $order): void
    {
        DB::transaction(function () use ($order) {
            $order = Order::whereKey($order->id)->lockForUpdate()->with('items')->first();
            if (!$order || $order->payment_status === 'paid') {
                return; // déjà traité
            }

            $order->update([
                'payment_status' => 'paid',
                'status' => 'confirmed',
                'confirmed_at' => now(),
            ]);

            // Créditer les gains en attente de chaque vendeur (séquestre plateforme)
            $sellerTotals = [];
            foreach ($order->items as $item) {
                $sellerTotals[$item->seller_id] = ($sellerTotals[$item->seller_id] ?? 0) + (float) $item->total_price;
            }
            foreach ($sellerTotals as $sellerId => $amount) {
                User::where('id', $sellerId)->increment('pending_earnings', $amount);
            }

            // Enregistrer une trace dans l'historique des transactions du client.
            // N.B. : le solde du wallet n'est PAS modifié — l'argent provient de Mobile
            // Money (KPay PayIn direct), pas du solde. Cet enregistrement sert uniquement
            // à rendre l'achat visible dans l'historique des paiements (GET /v1/wallet/transactions).
            $buyerBalance = (float) (User::where('id', $order->user_id)->value('kpay_wallet_balance') ?? 0);
            WalletTransaction::create([
                'user_id' => $order->user_id,
                'type' => 'debit',
                'amount' => (float) $order->total,
                'balance_before' => $buyerBalance,
                'balance_after' => $buyerBalance,
                'description' => "Achat - Commande #{$order->order_number}",
                'reference_type' => 'order',
                'reference_id' => $order->id,
                'metadata' => [
                    'payment_method' => 'kpay_direct',
                    'payment_reference' => $order->payment_reference,
                    'subtotal' => (float) $order->subtotal,
                    'delivery_fee' => (float) $order->delivery_fee,
                ],
                'status' => 'completed',
                'provider' => 'kpay',
            ]);

            Log::info('[OrderService] Commande KPay confirmée (payée)', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
            ]);
        });

        // Notifier le client (hors transaction)
        try {
            $this->fcmService->sendToUser(
                $order->user,
                '✅ Paiement confirmé',
                "Votre paiement pour la commande #{$order->order_number} a été confirmé.",
                ['type' => 'order_paid', 'order_id' => (string) $order->id, 'order_number' => $order->order_number]
            );
        } catch (\Exception $e) {
            Log::warning('[OrderService] FCM order_paid échec: ' . $e->getMessage());
        }
    }

    /**
     * Calcule la distance entre deux points GPS (Haversine).
     */
    private function calculateDistance(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat / 2) * sin($dLat / 2) +
             cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
             sin($dLon / 2) * sin($dLon / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }
}
