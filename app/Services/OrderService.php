<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ProductPriceTier;
use App\Models\ImportCountry;
use App\Models\ImportShippingOption;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Models\DelivererCompany;
use App\Models\DeliveryZone;
use App\Models\DeliveryPricelist;
use App\Services\WalletService;
use App\Services\DeliveryQuoteService;
use App\Services\OrderTrackingService;
use App\Services\FirebaseMessagingService;
use App\Support\CountryCode;
use App\Support\ImportHub;
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
     * Offres de livraison chiffrées pour un panier (poids réel, zones, trajets, TVA).
     * Cf. DeliveryQuoteService — même calcul qu'à la création de la commande.
     */
    public function deliveryQuotes(array $items, ?float $latitude = null, ?float $longitude = null, ?string $city = null, ?string $country = null, ?string $quarter = null, ?string $address = null): array
    {
        return app(DeliveryQuoteService::class)->quotes($items, $latitude, $longitude, $city, $country, $quarter, $address);
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
        ?int $deliveryZoneId,
        string $walletProvider,
        ?string $deliveryAddress = null,
        ?string $deliveryAddressDetails = null,
        ?string $customerPhone = null,
        ?float $deliveryLatitude = null,
        ?float $deliveryLongitude = null,
        ?string $notes = null,
        string $paymentMode = 'wallet', // 'wallet' (escrow depuis solde) | 'kpay_direct' (PayIn KPay)
        ?string $kpayProvider = null,   // code opérateur KPay (mode kpay_direct)
        ?string $kpayPhone = null,      // numéro Mobile Money (mode kpay_direct)
        ?int $deliveryRouteId = null,   // trajet transporteur (SOLEX interurbain, DHL…)
        ?string $deliveryCity = null,   // ville de livraison (choix du partenaire)
        ?string $deliveryCountry = null,
        ?int $deliveryGridId = null,    // grille urbaine zone à zone (ex. SOLEX Douala)
        ?string $deliveryVehicle = null, // moto, tricycle, 600kg, 1t
        ?string $deliveryQuarter = null  // quartier de l'acheteur (zone d'arrivée)
    ): Order {
        return DB::transaction(function () use (
            $client, $items, $deliveryCompanyId, $deliveryZoneId, $walletProvider,
            $deliveryRouteId, $deliveryCity, $deliveryCountry, $deliveryGridId, $deliveryVehicle, $deliveryQuarter,
            $deliveryAddress,
            $deliveryAddressDetails,
            $customerPhone,
            $deliveryLatitude,
            $deliveryLongitude,
            $notes,
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
            $sellerSubtotal = 0;
            $itemRates = [];
            $orderItems = [];
            $sellers = [];

            foreach ($items as $item) {
                $product = Product::lockForUpdate()->findOrFail($item['product_id']);

                if (!in_array($product->status, ['published', 'active'])) {
                    throw new \Exception("Le produit '{$product->name}' n'est plus disponible.");
                }

                $variant = null;
                if (!empty($item['variant_id'])) {
                    // Variante d'un autre produit : message clair pour l'acheteur,
                    // pas l'exception technique du modèle.
                    $variant = ProductVariant::where('product_id', $product->id)
                        ->lockForUpdate()
                        ->find($item['variant_id']);
                    if (!$variant) {
                        throw new \Exception("La variante sélectionnée pour '{$product->name}' n'existe pas.");
                    }
                    if (!$variant->is_active) {
                        throw new \Exception("La variante sélectionnée pour '{$product->name}' n'est plus disponible.");
                    }
                } elseif ($product->variants()->exists()) {
                    throw new \Exception("Veuillez sélectionner une variante pour '{$product->name}'.");
                }

                $availableStock = $variant?->stock ?? $product->stock;
                if ($availableStock !== null && $availableStock < $item['quantity']) {
                    throw new \Exception("Stock insuffisant pour '{$product->name}'. Disponible: {$availableStock}");
                }

                // Prix du produit converti en XAF (devise pivot) au taux du MOMENT de la
                // commande. Le vendeur peut fixer son prix dans une autre devise ; toute la
                // chaîne aval (escrow, wallet, livraison, payin) reste en XAF. Erreur stricte
                // si aucun taux fiable : on ne devine jamais un montant à débiter.
                //
                // Commission ASSO en MAJORATION : l'acheteur paie le prix vendeur majoré
                // du taux admin (exactement le prix public affiché) ; le vendeur touchera
                // son prix. Les deux sont figés sur la ligne de commande.
                $sourceCurrency = strtoupper($product->currency ?? 'XAF');
                $sellerSourceUnit = (float) $product->price + (float) ($variant?->price_adjustment ?? 0);
                $commissionRate = CommissionService::rateForProduct($product);
                $buyerSourceUnit = CommissionService::markup($sellerSourceUnit, $commissionRate, $sourceCurrency);
                if ($sourceCurrency === 'XAF') {
                    $unitPrice = $buyerSourceUnit;
                    $sellerUnitPrice = $sellerSourceUnit;
                } else {
                    $conv = \App\Services\ExchangeRateService::convert($sourceCurrency, 'XAF', $buyerSourceUnit);
                    $sellerConv = \App\Services\ExchangeRateService::convert($sourceCurrency, 'XAF', $sellerSourceUnit);
                    if (empty($conv['success']) || $conv['amount'] === null || empty($sellerConv['success']) || $sellerConv['amount'] === null) {
                        throw new \Exception("Conversion {$sourceCurrency} → XAF indisponible pour '{$product->name}'. Réessayez plus tard.");
                    }
                    $unitPrice = round((float) $conv['amount'], 2);
                    $sellerUnitPrice = min($unitPrice, round((float) $sellerConv['amount'], 2));
                }

                $quantity = $item['quantity'];
                $totalPrice = $unitPrice * $quantity;
                $subtotal += $totalPrice;
                $sellerSubtotal += $sellerUnitPrice * $quantity;
                $itemRates[] = $commissionRate;

                $orderItems[] = [
                    'product_id' => $product->id,
                    'product_variant_id' => $variant?->id,
                    'variant_attributes' => $variant?->attributes,
                    'seller_id' => $product->user_id,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,      // XAF (pivot) — prix acheteur, commission incluse
                    'total_price' => $totalPrice,    // XAF (pivot)
                    'seller_unit_price' => $sellerUnitPrice,               // ce que touche le vendeur
                    'seller_total_price' => $sellerUnitPrice * $quantity,
                    'commission_rate' => $commissionRate,
                ];

                // Collecter les vendeurs pour notification
                if (!in_array($product->user_id, $sellers)) {
                    $sellers[] = $product->user_id;
                }

                // Décrémenter le stock
                if ($product->stock !== null) {
                    $product->decrement('stock', $quantity);
                }
                if ($variant) {
                    $variant->decrement('stock', $quantity);
                }
            }

            // 2. Frais de livraison recalculés côté serveur, même calcul que l'offre
            //    affichée à l'acheteur : poids réel du panier, zone ou trajet, TVA.
            $quote = app(DeliveryQuoteService::class)->quoteFor(
                $items,
                $deliveryCompanyId,
                $deliveryZoneId,
                $deliveryRouteId,
                $deliveryLatitude,
                $deliveryLongitude,
                $deliveryCity ?? $deliveryAddress,
                $deliveryCountry,
                $deliveryGridId,
                $deliveryVehicle,
                $deliveryQuarter,
                $deliveryAddress,
            );
            $baseDeliveryPrice = (float) $quote['base_price'];   // part transporteur, TVA comprise
            $assoCommission = (float) $quote['asso_commission'];
            $deliveryFee = (float) $quote['delivery_price'];

            Log::info("[OrderService] Frais de livraison calculés", [
                'mode' => $quote['delivery_mode'],
                'weight_kg' => $quote['weight_kg'],
                'breakdown' => $quote['breakdown'],
            ]);

            $total = $subtotal + $deliveryFee;

            $isKpayDirect = $paymentMode === 'kpay_direct';
            $isStripeDirect = $paymentMode === 'stripe_direct';
            // Paiements « directs » (Mobile Money KPay ou carte Stripe native) : l'argent
            // est encaissé en dehors du solde wallet, la commande reste 'pending' de paiement.
            $isDirect = $isKpayDirect || $isStripeDirect;

            // 3. Mode wallet : verrouiller les fonds du client (escrow depuis le solde).
            //    Modes directs (kpay_direct / paypal_direct) : pas de verrou — le client
            //    paie via KPay (PayIn) ou PayPal (checkout) ci-dessous.
            if (!$isDirect) {
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

            // 4. Créer la commande — commission ASSO = majoration payée par l'acheteur.
            $uniqueRates = array_values(array_unique($itemRates));
            $saleCommission = [
                'rate' => count($uniqueRates) === 1 ? $uniqueRates[0] : null,
                'commission' => round($subtotal - $sellerSubtotal, 2),
                'vendor_net' => round($sellerSubtotal, 2),
            ];
            $order = Order::create([
                'user_id' => $client->id,
                'status' => 'pending',
                'subtotal' => $subtotal,
                'delivery_fee' => $deliveryFee,
                'base_delivery_price' => $baseDeliveryPrice,
                'delivery_commission' => $assoCommission,
                // Commission ASSO sur la vente, figée à la création (cf. CommissionService).
                'sale_commission_rate' => $saleCommission['rate'],
                'sale_commission' => $saleCommission['commission'],
                'vendor_net_amount' => $saleCommission['vendor_net'],
                'total' => $total,
                'delivery_address' => $deliveryAddress,
                'delivery_address_details' => $deliveryAddressDetails,
                'customer_phone' => $customerPhone,
                'delivery_latitude' => $deliveryLatitude,
                'delivery_longitude' => $deliveryLongitude,
                'delivery_company_id' => $deliveryCompanyId,
                'delivery_zone_id' => $quote['zone_id'],
                'delivery_mode' => $quote['delivery_mode'],
                'delivery_route_id' => $quote['route_id'],
                'delivery_city_grid_id' => $quote['grid_id'],
                'delivery_vehicle' => $quote['vehicle'],
                'shipping_weight_kg' => $quote['weight_kg'],
                'delivery_vat_amount' => $quote['breakdown']['vat_amount'],
                'delivery_breakdown' => $this->deliverySnapshot($quote),
                'payment_method' => match (true) {
                    $isKpayDirect => 'kpay_direct',
                    $isStripeDirect => 'stripe_direct',
                    default => 'wallet_' . $walletProvider,
                },
                // Modes directs : en attente du paiement (Mobile Money / carte) ;
                // wallet : déjà payé (fonds bloqués en escrow).
                'payment_status' => $isDirect ? 'pending' : 'paid',
                'notes' => $notes,
            ]);

            // Créer les items
            foreach ($orderItems as $itemData) {
                $order->items()->create($itemData);
            }

            app(OrderTrackingService::class)->record($order, 'pending', $deliveryCity, "{$quote['company_name']} — {$quote['route_label']}", 'buyer', $client->id);

            // 4b. Initier le paiement direct (KPay / carte Stripe). Pour la carte, pose
            //     les attributs transitoires client_secret / payment_intent_id sur $order.
            //     Logique partagée avec la commande EN GROS — voir initiateDirectPayment().
            $this->initiateDirectPayment($order, $total, $paymentMode, $kpayProvider, $kpayPhone);

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

            // Notification vendeur(s).
            // En modes directs (kpay_direct / stripe_direct), la commande n'est pas encore
            // payée (PayIn Mobile Money / carte en attente) : on ne prévient les vendeurs
            // qu'à la confirmation du paiement (voir confirm*OrderPayment) pour ne pas les
            // solliciter sur une commande qui pourrait ne jamais être réglée.
            if (!$isDirect) {
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
            }

            $order->load(['items.product.primaryImage', 'items.product.images', 'deliveryCompany', 'deliveryZone']);

            Log::info("[OrderService] Commande créée avec succès", [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'total' => $total,
                'payment_mode' => $paymentMode,
            ]);

            // Les attributs transitoires (client_secret / payment_intent_id /
            // stripe_publishable_key) pour la carte native ont été posés sur $order par
            // initiateDirectPayment ; ils sont lus par le contrôleur, jamais persistés.

            return $order;
        });
    }

    /**
     * Crée une commande EN GROS (module ASSO CHINA / DUBAÏ / TURQUIE).
     *
     * Diffère de createOrder : pas de livraison locale (zone/partenaire) mais une
     * EXPÉDITION internationale ; le prix vient des PALIERS (product_price_tiers) et
     * chaque ligne doit respecter la quantité minimale (« cota ») du palier choisi.
     * Le paiement réutilise initiateDirectPayment (KPay / PayPal / Stripe / wallet).
     *
     * @param array $items  [ ['product_id'=>int,'price_tier_id'=>int,'quantity'=>int], ... ]
     */
    public function createWholesaleOrder(
        User $client,
        array $items,
        int $shippingOptionId,
        float $shippingWeightKg = 0,
        float $shippingCbm = 0,
        ?string $deliveryAddress = null,
        string $paymentMode = 'kpay_direct',
        ?string $kpayProvider = null,
        ?string $kpayPhone = null,
        ?string $notes = null,
        // Livraison SOLEX de Douala jusqu'au client : offre choisie dans l'app
        // (company_id, zone_id, route_id, grid_id, vehicle, quarter, city, country,
        // latitude, longitude, address_details, customer_phone).
        array $delivery = []
    ): Order {
        return DB::transaction(function () use (
            $client, $items, $shippingOptionId, $shippingWeightKg, $shippingCbm,
            $deliveryAddress, $paymentMode, $kpayProvider, $kpayPhone, $notes, $delivery
        ) {
            $subtotal = 0;
            $orderItems = [];
            $countryCode = null;
            $calculatedWeightKg = 0;
            $hasMissingWeight = false;

            // Le minimum d'un palier porte sur le total commandé pour ce produit et
            // ce palier, toutes variantes confondues : 300 rouges + 200 noires d'un
            // palier « minimum 500 » l'atteignent, même si aucune couleur seule ne
            // l'atteint. Une ligne par couleur ne doit pas multiplier le minimum.
            $tierTotals = [];
            foreach ($items as $item) {
                $key = (int) $item['product_id'] . ':' . (int) $item['price_tier_id'];
                $tierTotals[$key] = ($tierTotals[$key] ?? 0) + (int) $item['quantity'];
            }

            foreach ($items as $item) {
                $product = Product::lockForUpdate()->findOrFail($item['product_id']);
                if (!$product->is_wholesale || $product->status !== 'active') {
                    throw new \Exception("Le produit '{$product->name}' n'est pas disponible en gros.");
                }

                $tier = ProductPriceTier::where('product_id', $product->id)
                    ->where('id', $item['price_tier_id'])
                    ->where('is_active', true)
                    ->firstOrFail();

                // Variante choisie (couleur, taille…) : conservée sur la ligne pour le fournisseur.
                $variant = null;
                if (!empty($item['variant_id'])) {
                    $variant = ProductVariant::where('product_id', $product->id)
                        ->where('is_active', true)
                        ->find($item['variant_id']);
                    if (!$variant) {
                        throw new \Exception("La variante sélectionnée pour '{$product->name}' n'est plus disponible.");
                    }
                }

                $quantity = (int) $item['quantity'];
                $tierTotal = $tierTotals[$product->id . ':' . $tier->id] ?? $quantity;
                if ($tierTotal < $tier->min_quantity) {
                    throw new \Exception("Quantité minimale non atteinte pour '{$product->name}' ({$tier->label}) : minimum {$tier->min_quantity} au total, toutes options confondues.");
                }

                // Prix du palier converti en XAF (devise pivot) au taux du moment.
                $tierCurrency = strtoupper($tier->currency ?? 'XAF');
                $unitPrice = (float) $tier->unit_price;
                if ($tierCurrency !== 'XAF') {
                    $conv = \App\Services\ExchangeRateService::convert($tierCurrency, 'XAF', $unitPrice);
                    if (empty($conv['success']) || $conv['amount'] === null) {
                        throw new \Exception("Conversion {$tierCurrency} → XAF indisponible pour '{$product->name}'.");
                    }
                    $unitPrice = round((float) $conv['amount'], 2);
                }

                $lineTotal = $unitPrice * $quantity;
                $subtotal += $lineTotal;
                $countryCode = $countryCode ?? $product->origin_country;
                // Poids d'une unité du palier (pack, bidon, pièce), sinon celui de la fiche.
                $unitWeight = $tier->weight_kg > 0 ? (float) $tier->weight_kg : $product->weightKg();
                if ($unitWeight) {
                    $calculatedWeightKg += $unitWeight * $quantity;
                } else {
                    $hasMissingWeight = true;
                }

                $orderItems[] = [
                    'product_id' => $product->id,
                    'product_variant_id' => $variant?->id,
                    'variant_attributes' => $variant?->attributes,
                    'seller_id' => $product->user_id,
                    'price_tier_id' => $tier->id,
                    'tier_label' => $tier->label,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'total_price' => $lineTotal,
                ];
            }

            // Expédition internationale : coût selon l'option choisie (poids / volume / forfait).
            $shipping = ImportShippingOption::where('id', $shippingOptionId)->where('is_active', true)->firstOrFail();
            if ($shipping->rate_type === 'per_kg') {
                if ($hasMissingWeight || $calculatedWeightKg <= 0) {
                    throw new \Exception("Le poids du colis doit d'abord être renseigné par ASSO avant le paiement.");
                }
                // Calcul autoritaire côté serveur : ne jamais faire saisir ni faire
                // confiance au poids envoyé par le client.
                $shippingWeightKg = $calculatedWeightKg;
            }
            $shippingCost = $shipping->computeCost($shippingWeightKg, $shippingCbm);

            // Puis SOLEX, de l'entrepôt ASSO de Douala jusqu'au client : prix recalculé
            // côté serveur (même calcul que l'offre affichée), payé avec la commande.
            if (empty($delivery['company_id'])) {
                throw new \Exception("Indiquez votre adresse de livraison : la commande arrive à Douala, puis SOLEX la livre jusqu'à vous.");
            }
            $quote = app(DeliveryQuoteService::class)->quoteFor(
                array_map(fn ($item) => [
                    'product_id' => (int) $item['product_id'],
                    'quantity' => (int) $item['quantity'],
                    'price_tier_id' => (int) $item['price_tier_id'],
                ], $items),
                (int) $delivery['company_id'],
                $delivery['zone_id'] ?? null,
                $delivery['route_id'] ?? null,
                $delivery['latitude'] ?? null,
                $delivery['longitude'] ?? null,
                ($delivery['city'] ?? null) ?: $deliveryAddress,
                $delivery['country'] ?? null,
                $delivery['grid_id'] ?? null,
                $delivery['vehicle'] ?? null,
                $delivery['quarter'] ?? null,
                $deliveryAddress,
            );
            $localDeliveryFee = (float) $quote['delivery_price'];
            $total = $subtotal + $shippingCost + $localDeliveryFee;

            $isDirect = in_array($paymentMode, ['kpay_direct', 'stripe_direct']);

            // Mode wallet : escrow depuis le solde. Modes directs : encaissement externe.
            if (!$isDirect) {
                $this->walletService->lockFunds(
                    $client, $total, 'Escrow commande gros - En attente de validation vendeur',
                    'order', null, ['wholesale' => true], $kpayProvider ?? 'kpay'
                );
            }

            // Catalogue import (prix fixés par ASSO) : aucune majoration.
            $saleCommission = ['rate' => 0.0, 'commission' => 0.0, 'vendor_net' => (float) $subtotal];
            $order = Order::create([
                'user_id' => $client->id,
                'status' => 'pending',
                'is_wholesale' => true,
                'import_country_code' => $countryCode,
                'shipping_mode' => $shipping->mode,
                'shipping_option_id' => $shipping->id,
                'delivery_mode' => Order::DELIVERY_CARRIER,
                'shipping_weight_kg' => $calculatedWeightKg > 0 ? $calculatedWeightKg : $quote['weight_kg'],
                'subtotal' => $subtotal,
                // Frais de livraison = trajet jusqu'à Douala (ASSO) + SOLEX jusqu'au client.
                'delivery_fee' => $shippingCost + $localDeliveryFee,
                'import_shipping_fee' => $shippingCost,
                // Part SOLEX (TTC) et commission ASSO sur sa course, réglées comme une livraison.
                'base_delivery_price' => (float) $quote['base_price'],
                'delivery_commission' => (float) $quote['asso_commission'],
                'delivery_company_id' => $quote['company_id'],
                'delivery_zone_id' => $quote['zone_id'],
                'delivery_route_id' => $quote['route_id'],
                'delivery_city_grid_id' => $quote['grid_id'],
                'delivery_vehicle' => $quote['vehicle'],
                'delivery_vat_amount' => $quote['breakdown']['vat_amount'],
                'delivery_breakdown' => $this->deliverySnapshot($quote) + [
                    'import_leg' => $this->importLeg($shipping, $shippingCost, $countryCode),
                ],
                'sale_commission_rate' => $saleCommission['rate'],
                'sale_commission' => $saleCommission['commission'],
                'vendor_net_amount' => $saleCommission['vendor_net'],
                'total' => $total,
                'delivery_address' => $deliveryAddress,
                'delivery_address_details' => $delivery['address_details'] ?? null,
                'customer_phone' => $delivery['customer_phone'] ?? null,
                'delivery_latitude' => $delivery['latitude'] ?? null,
                'delivery_longitude' => $delivery['longitude'] ?? null,
                'payment_method' => match (true) {
                    $paymentMode === 'kpay_direct' => 'kpay_direct',
                    $paymentMode === 'stripe_direct' => 'stripe_direct',
                    default => 'wallet_' . ($kpayProvider ?? 'kpay'),
                },
                'payment_status' => $isDirect ? 'pending' : 'paid',
                'notes' => $notes,
            ]);

            foreach ($orderItems as $itemData) {
                $order->items()->create($itemData);
            }

            app(OrderTrackingService::class)->record(
                $order, 'pending', null,
                'Commande import — ' . ($shipping->carrier ?: (ImportShippingOption::MODE_LABELS[$shipping->mode] ?? $shipping->mode))
                    . ' jusqu\'à ' . ImportHub::CITY . ", puis {$quote['company_name']} — {$quote['route_label']}",
                'buyer', $client->id,
            );

            // Paiement (réutilise la logique des rails directs). Pour la carte native,
            // pose les attributs transitoires client_secret / payment_intent_id sur $order.
            $this->initiateDirectPayment($order, $total, $paymentMode, $kpayProvider, $kpayPhone);

            $this->fcmService->sendToUser(
                $client,
                'Commande en gros créée',
                "Votre commande gros #{$order->order_number} a été créée. En attente de paiement/validation.",
                ['type' => 'wholesale_order_created', 'order_id' => (string) $order->id, 'order_number' => $order->order_number]
            );

            // Payée tout de suite via le portefeuille : le vendeur peut préparer.
            // (En paiement direct, il est prévenu à la confirmation du paiement.)
            if (!$isDirect) {
                $order->notifySellers(
                    'Nouvelle commande en gros',
                    "Commande gros #{$order->order_number} payée : {$order->items()->sum('quantity')} article(s) à préparer.",
                    ['type' => 'new_order_vendor'],
                );
            }

            $order->load(['items.product.primaryImage']);

            Log::info('[OrderService] Commande GROS créée', [
                'order_id' => $order->id, 'subtotal' => $subtotal, 'shipping' => $shippingCost, 'total' => $total,
            ]);

            return $order;
        });
    }

    /**
     * Confirme le paiement KPay direct d'une commande (idempotent).
     *
     * Marque UNIQUEMENT la commande comme payée (payment_status = 'paid') : elle
     * reste au statut 'pending' afin de suivre le MÊME cycle que le mode wallet,
     * c.-à-d. attendre la validation du vendeur (VendorOrderController::validate),
     * qui crédite/bloque les fonds du vendeur et du livreur (escrow). L'argent est
     * séquestré par la plateforme (compte marchand KPay) et sera libéré vers le
     * vendeur/livreur à la confirmation de livraison (DeliveryController::complete).
     */
    public function confirmKpayOrderPayment(Order $order): void
    {
        $sellers = [];
        $lateRefund = false;

        DB::transaction(function () use ($order, &$sellers, &$lateRefund) {
            $order = Order::whereKey($order->id)->lockForUpdate()->with('items')->first();
            if (!$order || in_array($order->payment_status, [Order::PAYMENT_PAID, Order::PAYMENT_REFUNDED], true)) {
                return; // déjà traité
            }

            // Payé, mais on NE confirme PAS la commande : le vendeur doit encore la
            // valider (comme en mode wallet). Le crédit escrow vendeur/livreur se fait
            // dans validate(), et non plus via pending_earnings (modèle unifié).
            $order->update([
                'payment_status' => 'paid',
            ]);

            $sellers = $order->items->pluck('seller_id')->unique()->values()->all();

            // Enregistrer une trace dans l'historique des transactions du client.
            // N.B. : le solde du wallet n'est PAS modifié — l'argent provient de Mobile
            // Money (KPay PayIn direct), pas du solde. Cet enregistrement sert uniquement
            // à rendre l'achat visible dans l'historique des paiements (GET /v1/wallet/transactions).
            // Solde indicatif (non modifié : l'argent vient du rail direct, pas du Wallet).
            $buyerBalance = (float) (User::find($order->user_id)?->kpayBalanceFor('XAF') ?? 0);
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

            // Paiement arrivé APRÈS l'annulation de la commande (annulation client ou
            // échec présumé) : l'argent est encaissé, on le rend sur le Wallet ASSO.
            if ($order->status === 'cancelled') {
                $this->refundBuyer(
                    $order,
                    "Remboursement commande #{$order->order_number} — paiement reçu après annulation",
                    ['late_payment' => true]
                );
                $sellers = [];
                $lateRefund = true;
            }

            Log::info('[OrderService] Commande KPay confirmée (payée)', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
            ]);
        });

        if ($lateRefund) {
            $this->notifyLateRefund($order);
            return;
        }

        // Notifier le client (hors transaction)
        try {
            $this->fcmService->sendToUser(
                $order->user,
                'Paiement confirmé',
                "Votre paiement pour la commande #{$order->order_number} a été confirmé. En attente de validation du vendeur.",
                ['type' => 'order_paid', 'order_id' => (string) $order->id, 'order_number' => $order->order_number]
            );
        } catch (\Exception $e) {
            Log::warning('[OrderService] FCM order_paid échec: ' . $e->getMessage());
        }

        // Prévenir le(s) vendeur(s) : la commande est désormais payée et actionnable.
        // (En mode kpay_direct la notif « Nouvelle commande » n'est PAS envoyée à la
        // création — voir createOrder — mais seulement ici, une fois le paiement acquis.)
        $client = $order->user;
        foreach ($sellers as $sellerId) {
            $seller = User::find($sellerId);
            if (!$seller) {
                continue;
            }
            try {
                $this->fcmService->sendToUser(
                    $seller,
                    'Nouvelle commande reçue',
                    "Vous avez reçu une nouvelle commande #{$order->order_number}"
                        . ($client ? " de {$client->first_name}" : '')
                        . " ({$order->formatted_total}).",
                    [
                        'type' => 'new_order_vendor',
                        'order_id' => (string) $order->id,
                        'order_number' => $order->order_number,
                        'total' => (string) $order->total,
                        'client_name' => $client ? trim($client->first_name . ' ' . $client->last_name) : '',
                    ]
                );
            } catch (\Exception $e) {
                Log::warning('[OrderService] FCM new_order_vendor échec: ' . $e->getMessage());
            }
        }
    }

    /**
     * Échec/annulation du paiement KPay direct d'une commande (idempotent).
     *
     * Le PayIn n'a pas abouti : on restaure le stock décrémenté à la création et on
     * annule la commande, afin de ne pas laisser une commande fantôme en 'pending'
     * avec du stock verrouillé indéfiniment.
     */
    public function failKpayOrderPayment(Order $order): void
    {
        DB::transaction(function () use ($order) {
            $order = Order::whereKey($order->id)->lockForUpdate()->with('items.product')->first();

            // Idempotence : ne rien faire si déjà payé (course polling/webhook) ou déjà annulé.
            if (!$order
                || $order->payment_method !== 'kpay_direct'
                || $order->payment_status === 'paid'
                || $order->status === 'cancelled') {
                return;
            }

            // Restaurer le stock décrémenté lors de la création
            foreach ($order->items as $item) {
                $item->restoreStock();
            }

            $order->update([
                'payment_status' => 'failed',
                'status' => 'cancelled',
                'cancel_reason' => 'Paiement Mobile Money non abouti',
                'cancelled_at' => now(),
            ]);

            Log::info('[OrderService] Commande KPay échouée — stock restauré, commande annulée', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
            ]);
        });

        try {
            $this->fcmService->sendToUser(
                $order->user,
                'Paiement échoué',
                "Le paiement de la commande #{$order->order_number} n'a pas abouti. La commande a été annulée.",
                ['type' => 'order_payment_failed', 'order_id' => (string) $order->id, 'order_number' => $order->order_number]
            );
        } catch (\Exception $e) {
            Log::warning('[OrderService] FCM order_payment_failed échec: ' . $e->getMessage());
        }
    }

    /**
     * Initie le paiement DIRECT d'une commande déjà créée (commande normale OU en gros).
     * Renvoie toujours null : pour la carte native, les attributs transitoires
     * client_secret / payment_intent_id / stripe_publishable_key sont posés sur $order.
     * Lance une exception en cas d'échec → la transaction appelante fait un rollback.
     *
     * @param string $paymentMode wallet | kpay_direct | stripe_direct
     */
    public function initiateDirectPayment(
        Order $order,
        float $total,
        string $paymentMode,
        ?string $kpayProvider = null,
        ?string $kpayPhone = null
    ): ?string {
        // Mode kpay_direct : PayIn Mobile Money (devise de l'opérateur, déduite du numéro).
        if ($paymentMode === 'kpay_direct') {
            $payCurrency = \App\Services\KPayCatalog::currencyForProvider($kpayProvider);
            $payAmount = (float) round($total);

            if ($payCurrency !== 'XAF') {
                $converted = \App\Services\ExchangeRateService::convertAmount('XAF', $payCurrency, $total);
                if ($converted === null) {
                    throw new \Exception("Conversion XAF → {$payCurrency} indisponible. Réessayez plus tard.");
                }
                $payAmount = (float) round($converted);
            }

            $order->update(['payment_currency' => $payCurrency, 'payment_amount' => $payAmount]);

            $kpayResult = app(\App\Services\KPayService::class)->initializePayment([
                'amount' => $payAmount,
                'provider' => $kpayProvider,
                'phone_number' => $kpayPhone,
                'description' => "Commande {$order->order_number}",
                'external_reference' => $order->order_number,
            ]);

            if (empty($kpayResult['success'])) {
                throw new \Exception($kpayResult['message'] ?? "Échec de l'initiation du paiement KPay.");
            }

            $order->update(['payment_reference' => $kpayResult['id'] ?? null]);
            Log::info('[OrderService] PayIn KPay initié', ['order_id' => $order->id, 'charged' => $payAmount, 'currency' => $payCurrency]);
            return null;
        }

        // Mode stripe_direct : carte NATIVE (PaymentIntent). On renvoie un client_secret
        // que le mobile confirme via la Payment Sheet (SDK flutter_stripe). La confirmation
        // serveur se fait ensuite au polling (syncStripeOrder → retrievePaymentIntent) et/ou
        // via le webhook payment_intent.succeeded (metadata asso_kind=order). Aucune WebView.
        if ($paymentMode === 'stripe_direct') {
            $stripe = app(\App\Services\StripeService::class);
            if (!$stripe->isConfigured()) {
                throw new \Exception('Le paiement par carte est momentanément indisponible.');
            }

            $stripeCurrency = \App\Services\PaymentMethodService::currencyFor('stripe') ?? 'USD';
            $stripeAmount = strtoupper($stripeCurrency) === 'XAF'
                ? (float) round($total)
                : \App\Services\ExchangeRateService::convertAmount('XAF', $stripeCurrency, $total);
            if ($stripeAmount === null) {
                throw new \Exception("Conversion XAF → {$stripeCurrency} indisponible pour le paiement carte.");
            }

            $intent = $stripe->createPaymentIntent(
                (float) $stripeAmount,
                $stripeCurrency,
                ['asso_kind' => 'order', 'order_id' => (string) $order->id]
            );

            if (empty($intent['id']) || empty($intent['client_secret'])) {
                throw new \Exception("Échec de l'initiation du paiement carte (Stripe).");
            }

            $order->update([
                'payment_reference' => $intent['id'],
                'payment_currency' => strtoupper($stripeCurrency),
                'payment_amount' => round((float) $stripeAmount, 2),
            ]);

            // Attributs transitoires (non persistés) : consommés par le contrôleur pour
            // renvoyer le client_secret au mobile.
            $order->client_secret = $intent['client_secret'];
            $order->payment_intent_id = $intent['id'];
            $order->stripe_publishable_key = $intent['publishable_key'] ?? null;

            Log::info('[OrderService] PaymentIntent Stripe initié', ['order_id' => $order->id, 'payment_intent' => $intent['id']]);
            return null;
        }

        return null; // mode wallet : aucun checkout externe
    }

    /**
     * Synchronise l'état d'un paiement carte Stripe NATIF (idempotent), déclenché par le
     * polling GET /v1/orders/{id}/payment-status. On relit le PaymentIntent :
     *  - succeeded → on confirme la commande (payée)
     *  - canceled  → on échoue (stock restauré, commande annulée)
     *  - autres (requires_payment_method / processing…) → on reste en attente
     * (Le webhook payment_intent.succeeded confirme aussi, via metadata order_id.)
     */
    public function syncStripeOrder(Order $order): void
    {
        if ($order->payment_status !== 'pending'
            || $order->payment_method !== 'stripe_direct'
            || !$order->payment_reference) {
            return;
        }

        try {
            $intent = app(\App\Services\StripeService::class)->retrievePaymentIntent($order->payment_reference);
        } catch (\Throwable $e) {
            Log::warning('[OrderService] Stripe retrieve PaymentIntent: ' . $e->getMessage());
            return;
        }

        $status = strtolower($intent['status'] ?? '');

        if ($status === 'succeeded') {
            $this->confirmStripeOrderPayment($order);
        } elseif ($status === 'canceled') {
            $this->failStripeOrderPayment($order);
        }
    }

    /**
     * Confirme le paiement carte Stripe d'une commande (idempotent). Même sémantique que
     * confirmKpayOrderPayment : payment_status='paid' (commande reste 'pending' → validation
     * vendeur), trace wallet (solde inchangé — encaissé chez Stripe), notifications.
     */
    public function confirmStripeOrderPayment(Order $order): void
    {
        $sellers = [];
        $lateRefund = false;

        DB::transaction(function () use ($order, &$sellers, &$lateRefund) {
            $order = Order::whereKey($order->id)->lockForUpdate()->with('items')->first();
            if (!$order || in_array($order->payment_status, [Order::PAYMENT_PAID, Order::PAYMENT_REFUNDED], true)) {
                return;
            }

            $order->update(['payment_status' => 'paid']);
            $sellers = $order->items->pluck('seller_id')->unique()->values()->all();

            // Solde indicatif (non modifié : l'argent vient du rail direct, pas du Wallet).
            $buyerBalance = (float) (User::find($order->user_id)?->kpayBalanceFor('XAF') ?? 0);
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
                    'payment_method' => 'stripe_direct',
                    'payment_reference' => $order->payment_reference,
                    'subtotal' => (float) $order->subtotal,
                    'delivery_fee' => (float) $order->delivery_fee,
                ],
                'status' => 'completed',
                'provider' => 'stripe',
            ]);

            // Paiement arrivé APRÈS l'annulation de la commande (annulation client ou
            // échec présumé) : l'argent est encaissé, on le rend sur le Wallet ASSO.
            if ($order->status === 'cancelled') {
                $this->refundBuyer(
                    $order,
                    "Remboursement commande #{$order->order_number} — paiement reçu après annulation",
                    ['late_payment' => true]
                );
                $sellers = [];
                $lateRefund = true;
            }

            Log::info('[OrderService] Commande Stripe confirmée (payée)', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
            ]);
        });

        if ($lateRefund) {
            $this->notifyLateRefund($order);
            return;
        }

        try {
            $this->fcmService->sendToUser(
                $order->user,
                'Paiement confirmé',
                "Votre paiement pour la commande #{$order->order_number} a été confirmé. En attente de validation du vendeur.",
                ['type' => 'order_paid', 'order_id' => (string) $order->id, 'order_number' => $order->order_number]
            );
        } catch (\Exception $e) {
            Log::warning('[OrderService] FCM order_paid (stripe) échec: ' . $e->getMessage());
        }

        $client = $order->user;
        foreach ($sellers as $sellerId) {
            $seller = User::find($sellerId);
            if (!$seller) {
                continue;
            }
            try {
                $this->fcmService->sendToUser(
                    $seller,
                    'Nouvelle commande reçue',
                    "Vous avez reçu une nouvelle commande #{$order->order_number}"
                        . ($client ? " de {$client->first_name}" : '')
                        . " ({$order->formatted_total}).",
                    [
                        'type' => 'new_order_vendor',
                        'order_id' => (string) $order->id,
                        'order_number' => $order->order_number,
                        'total' => (string) $order->total,
                        'client_name' => $client ? trim($client->first_name . ' ' . $client->last_name) : '',
                    ]
                );
            } catch (\Exception $e) {
                Log::warning('[OrderService] FCM new_order_vendor (stripe) échec: ' . $e->getMessage());
            }
        }
    }

    /**
     * Échec/expiration d'un paiement carte Stripe (idempotent) : restaure le stock et annule.
     */
    public function failStripeOrderPayment(Order $order): void
    {
        DB::transaction(function () use ($order) {
            $order = Order::whereKey($order->id)->lockForUpdate()->with('items.product')->first();

            if (!$order
                || $order->payment_method !== 'stripe_direct'
                || $order->payment_status === 'paid'
                || $order->status === 'cancelled') {
                return;
            }

            foreach ($order->items as $item) {
                $item->restoreStock();
            }

            $order->update([
                'payment_status' => 'failed',
                'status' => 'cancelled',
                'cancel_reason' => 'Paiement carte (Stripe) non abouti',
                'cancelled_at' => now(),
            ]);

            Log::info('[OrderService] Commande Stripe échouée — stock restauré, commande annulée', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
            ]);
        });

        try {
            $this->fcmService->sendToUser(
                $order->user,
                'Paiement échoué',
                "Le paiement de la commande #{$order->order_number} n'a pas abouti. La commande a été annulée.",
                ['type' => 'order_payment_failed', 'order_id' => (string) $order->id, 'order_number' => $order->order_number]
            );
        } catch (\Exception $e) {
            Log::warning('[OrderService] FCM order_payment_failed (stripe) échec: ' . $e->getMessage());
        }
    }

    /**
     * Calcule la distance entre deux points GPS (Haversine).
     */
    /**
     * Validation d'une commande payée par son vendeur (ASSO pour un import en gros) :
     * confirmée, réglée (vendeur, livreur, ASSO), puis l'acheteur est prévenu.
     *
     * @throws \Exception si le paiement n'est pas acquis ou la commande déjà traitée
     */
    public function confirmBySeller(Order $order, User $vendor, string $actorType, ?int $actorId): Order
    {
        // Le paiement doit être acquis : sinon le vendeur serait crédité sur de l'argent
        // jamais encaissé (Mobile Money ou carte encore en attente).
        if ($order->payment_status !== Order::PAYMENT_PAID) {
            throw new \Exception("Le paiement de cette commande n'est pas encore confirmé.");
        }

        $confirmed = DB::transaction(function () use ($order, $vendor, $actorType, $actorId) {
            // Verrou + re-contrôle : deux validations simultanées ne créditent pas deux fois.
            $locked = Order::whereKey($order->id)->lockForUpdate()->first();
            if (!$locked || $locked->status !== 'pending') {
                throw new \Exception('Cette commande a déjà été traitée.');
            }
            $locked->update(['status' => 'confirmed', 'confirmed_at' => now()]);

            // Encaissement direct (sans escrow) : prélèvement de l'acheteur (wallet), puis
            // crédit du vendeur, de l'entreprise de livraison et d'ASSO. Idempotent.
            $this->settleOrder($locked, $vendor);
            app(OrderTrackingService::class)->record($locked, 'confirmed', null, null, $actorType, $actorId);

            return $locked;
        });

        if ($client = $confirmed->user) {
            $this->fcmService->sendToUser(
                $client,
                'Commande validée !',
                $confirmed->isCarrierDelivery()
                    ? "Votre commande #{$confirmed->order_number} a été acceptée par le vendeur. Le colis va être remis au transporteur."
                    : "Votre commande #{$confirmed->order_number} a été acceptée par le vendeur. En attente du livreur.",
                ['type' => 'order_confirmed', 'order_id' => (string) $confirmed->id, 'order_number' => $confirmed->order_number]
            );
        }

        return $confirmed;
    }

    /**
     * Refus d'une commande encore en attente : annulée, acheteur remboursé (wallet ou
     * Wallet ASSO), stock restauré, acheteur prévenu. Renvoie le montant rendu.
     *
     * @throws \Exception si la commande a déjà été traitée
     */
    public function rejectBySeller(Order $order, string $reason, string $actorType, ?int $actorId): float
    {
        $refunded = DB::transaction(function () use ($order, $reason, $actorType, $actorId) {
            // Verrou + re-contrôle : pas de refus après validation.
            $locked = Order::whereKey($order->id)->lockForUpdate()->with('items')->first();
            if (!$locked || $locked->status !== 'pending') {
                throw new \Exception('Cette commande a déjà été traitée.');
            }
            $locked->update(['status' => 'cancelled', 'cancel_reason' => $reason, 'cancelled_at' => now()]);

            $refunded = $this->refundBuyer(
                $locked,
                "Remboursement commande #{$locked->order_number} — refusée par le vendeur",
                ['cancel_reason' => $reason]
            );
            foreach ($locked->items as $item) {
                $item->restoreStock();
            }
            app(OrderTrackingService::class)->record($locked, 'cancelled', null, $reason, $actorType, $actorId);

            return $refunded;
        });

        if ($client = $order->user) {
            $this->fcmService->sendToUser(
                $client,
                'Commande refusée',
                $refunded > 0
                    ? "Votre commande #{$order->order_number} a été refusée. " . number_format($refunded, 0, ',', ' ') . " FCFA ont été rendus disponibles sur votre Wallet ASSO."
                    : "Votre commande #{$order->order_number} a été refusée.",
                ['type' => 'order_rejected', 'order_id' => (string) $order->id, 'order_number' => $order->order_number, 'reason' => $reason]
            );
        }

        return $refunded;
    }

    /**
     * Rembourse l'acheteur d'une commande annulée/refusée (idempotent, à appeler DANS
     * une transaction DB).
     *
     *  - Paiement wallet (escrow) : les fonds bloqués sont simplement débloqués.
     *  - Paiement direct (Mobile Money / carte) DÉJÀ encaissé : le montant est crédité
     *    sur le Wallet ASSO de l'acheteur (l'argent est sur le compte marchand ASSO) et
     *    payment_status passe à 'refunded'.
     *  - Paiement direct jamais abouti : rien à rembourser.
     *
     * Renvoie le montant rendu disponible à l'acheteur (0 si rien à rembourser).
     */
    public function refundBuyer(Order $order, string $label, array $metadata = []): float
    {
        $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

        // Déjà remboursée ou déjà réglée aux vendeurs : on ne touche plus aux fonds.
        if ($order->refunded_at || $order->settled_at) {
            return 0.0;
        }

        $client = $order->user;
        if (!$client) {
            return 0.0;
        }

        $amount = (float) $order->total;

        if ($order->isWalletPayment()) {
            if ($order->payment_status !== Order::PAYMENT_PAID) {
                return 0.0;
            }
            $this->walletService->unlockFunds(
                $client,
                $amount,
                $label,
                'order',
                $order->id,
                $metadata,
                'kpay'
            );
        } elseif ($order->isDirectPayment()) {
            if ($order->payment_status !== Order::PAYMENT_PAID) {
                return 0.0; // paiement jamais encaissé
            }
            $this->walletService->credit(
                $client,
                $amount,
                null,
                $label,
                array_merge($metadata, [
                    'order_id' => $order->id,
                    'refund' => true,
                    'original_payment_method' => $order->payment_method,
                    'original_payment_reference' => $order->payment_reference,
                ]),
                'kpay'
            );
        } else {
            return 0.0;
        }

        $order->update([
            'payment_status' => Order::PAYMENT_REFUNDED,
            'refunded_at' => now(),
        ]);

        Log::info('[OrderService] Acheteur remboursé', [
            'order_id' => $order->id,
            'amount' => $amount,
            'payment_method' => $order->payment_method,
        ]);

        return $amount;
    }

    /** Prévient l'acheteur qu'un paiement tardif a été crédité sur son Wallet. */
    /** Premier volet d'une commande en gros : pays d'origine → entrepôt ASSO de Douala. */
    private function importLeg(ImportShippingOption $shipping, float $price, ?string $countryCode): array
    {
        $country = ImportCountry::where('code', $countryCode)->value('name') ?? CountryCode::name($countryCode) ?? $countryCode;
        $mode = ImportShippingOption::MODE_LABELS[$shipping->mode] ?? $shipping->mode;

        return [
            'label' => "Expédition {$country} → " . ImportHub::CITY . " ({$mode})",
            'mode' => $shipping->mode,
            'carrier' => $shipping->carrier,
            'lead_time_days' => $shipping->lead_time_days,
            'price' => round($price),
        ];
    }

    /** Détail de la livraison figé sur la commande (affiché à l'acheteur, au vendeur, à l'admin). */
    private function deliverySnapshot(array $quote): array
    {
        return [
            'company_name' => $quote['company_name'],
            'service_type' => $quote['service_type'],
            'service_type_label' => $quote['service_type_label'],
            'service_mode' => $quote['service_mode'],
            'service_mode_label' => $quote['service_mode_label'],
            'route_label' => $quote['route_label'],
            'vehicle_label' => $quote['vehicle_label'],
            'delivery_option' => $quote['delivery_option'],
            'delivery_option_label' => $quote['delivery_option_label'],
            'lead_time' => $quote['lead_time'],
            'conditions' => $quote['conditions'],
            'price_grid' => $quote['price_grid'],
        ] + $quote['breakdown'];
    }

    private function notifyLateRefund(Order $order): void
    {
        try {
            $this->fcmService->sendToUser(
                $order->user,
                'Paiement remboursé',
                "Votre paiement pour la commande annulée #{$order->order_number} a été crédité sur votre Wallet ASSO.",
                ['type' => 'wallet_refund', 'order_id' => (string) $order->id, 'order_number' => $order->order_number]
            );
        } catch (\Exception $e) {
            Log::warning('[OrderService] FCM wallet_refund échec: ' . $e->getMessage());
        }
    }

    /**
     * Règle une commande validée par le vendeur (idempotent, à appeler DANS une
     * transaction DB) : prélève l'acheteur (mode wallet) puis crédite le vendeur de SON
     * prix, l'entreprise de livraison et ASSO (majorations vente + livraison).
     */
    public function settleOrder(Order $order, User $vendor): void
    {
        $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
        if ($order->settled_at) {
            return; // déjà réglée
        }
        if ($order->payment_status !== Order::PAYMENT_PAID) {
            throw new \Exception("Le paiement de cette commande n'est pas encore confirmé.");
        }

        // a) Mode wallet : prélèvement définitif des fonds bloqués depuis la création.
        if ($order->isWalletPayment()) {
            $this->walletService->releaseEscrow(
                $order->user,
                (float) $order->total,
                "Paiement commande #{$order->order_number} — validée par le vendeur",
                'order',
                $order->id,
                [],
                'kpay'
            );
        }

        // b) Part vendeur / ASSO figées à la création (majoration). Commande antérieure
        //    à la majoration : l'acheteur a payé le prix vendeur, tout revient au vendeur.
        $subtotal = (float) $order->subtotal;
        if ($order->vendor_net_amount === null) {
            $order->sale_commission_rate = 0;
            $order->sale_commission = 0;
            $order->vendor_net_amount = $subtotal;
        }
        $saleCommission = (float) $order->sale_commission;
        $vendorNet = (float) $order->vendor_net_amount;

        if ($vendorNet > 0) {
            $this->walletService->credit(
                $vendor,
                $vendorNet,
                null,
                "Vente commande #{$order->order_number}",
                [
                    'order_id' => $order->id,
                    'direct_settlement' => true,
                    'subtotal' => $subtotal,
                    'sale_commission' => $saleCommission,
                    'sale_commission_rate' => (float) $order->sale_commission_rate,
                ],
                'kpay'
            );
        }

        // c) Entreprise de livraison (prix de base de la course).
        $baseDeliveryPrice = (float) $order->base_delivery_price;
        if ($baseDeliveryPrice > 0 && $order->delivery_company_id) {
            $companyUserId = DelivererCompany::whereKey($order->delivery_company_id)->value('user_id');
            $companyUser = $companyUserId ? User::find($companyUserId) : null;
            if ($companyUser) {
                $this->walletService->credit(
                    $companyUser,
                    $baseDeliveryPrice,
                    null,
                    "Commission livraison #{$order->order_number}",
                    ['order_id' => $order->id, 'direct_settlement' => true],
                    'kpay'
                );
            }
        }

        // d) ASSO : commission vente + commission livraison.
        $assoTotal = $saleCommission + (float) $order->delivery_commission;
        if ($assoTotal > 0) {
            $platform = CommissionService::platformAccount();
            if ($platform) {
                $this->walletService->credit(
                    $platform,
                    $assoTotal,
                    null,
                    "Commission ASSO — Commande #{$order->order_number}",
                    [
                        'order_id' => $order->id,
                        'direct_settlement' => true,
                        'sale_commission' => $saleCommission,
                        'delivery_commission' => (float) $order->delivery_commission,
                    ],
                    'kpay'
                );
            } else {
                Log::warning('[OrderService] Compte plateforme ASSO introuvable, commission non créditée', [
                    'order_id' => $order->id,
                    'commission' => $assoTotal,
                ]);
            }
        }

        $order->settled_at = now();
        $order->save();

        Log::info('[OrderService] Commande réglée', [
            'order_id' => $order->id,
            'vendor_net' => $vendorNet,
            'sale_commission' => $saleCommission,
            'delivery_commission' => (float) $order->delivery_commission,
        ]);
    }
}
