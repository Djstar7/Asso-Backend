<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductPriceTier;
use App\Models\ImportShippingOption;
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
     * Filtre par ville si fournie.
     */
    public function getDeliveryPartnersWithPricing(int $productId, ?float $latitude = null, ?float $longitude = null, ?string $city = null): array
    {
        Log::info('');
        Log::info('═══════════════════════════════════════════════════════════════');
        Log::info('🚚 [OrderService] GET DELIVERY PARTNERS WITH PRICING');
        Log::info('═══════════════════════════════════════════════════════════════');
        Log::info('📦 Paramètres de recherche:', [
            'product_id' => $productId,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'city' => $city ?? 'NON FOURNIE',
        ]);

        $product = Product::findOrFail($productId);

        // Compteur de zones avant filtrage
        $totalZonesBeforeFilter = \App\Models\DeliveryZone::where('is_active', true)
            ->whereNotNull('center_latitude')
            ->whereNotNull('center_longitude')
            ->count();

        Log::info("📊 Total zones actives (avant filtrage ville): {$totalZonesBeforeFilter}");

        // Normaliser la ville du client pour comparaison
        $normalizedClientCity = $city ? $this->normalizeCity($city) : null;

        // Si la "ville" fournie n'est pas un vrai nom (ex: l'app envoie l'adresse
        // brute sous forme de coordonnées "5.4821, 10.4169" faute de géocodage),
        // on ignore le filtre ville et on se fie uniquement à la distance.
        if ($normalizedClientCity && !preg_match('/[a-z]/', $normalizedClientCity)) {
            Log::info("⚠️ Ville client non exploitable ('{$city}') → filtre ville désactivé, tri par distance");
            $normalizedClientCity = null;
        }

        Log::info("🔄 Ville client normalisée: " . ($normalizedClientCity ?? 'N/A'));

        // Récupérer TOUTES les zones actives (sans filtrage SQL par ville)
        $companies = DelivererCompany::where('is_active', true)
            ->with(['deliveryZones' => function ($q) {
                $q->where('is_active', true)
                    ->whereNotNull('center_latitude')
                    ->whereNotNull('center_longitude')
                    ->with('activePricelist');
            }, 'user'])
            ->get();

        $totalZonesFound = 0;
        foreach ($companies as $company) {
            $totalZonesFound += $company->deliveryZones->count();
        }

        Log::info("📊 Total zones actives trouvées: {$totalZonesFound}");
        Log::info("📊 Entreprises actives: {$companies->count()}");

        $partners = [];

        Log::info('');
        Log::info('🏢 TRAITEMENT DES ENTREPRISES ET ZONES:');
        Log::info('───────────────────────────────────────────────────────────────');

        foreach ($companies as $company) {
            Log::info("📦 Entreprise: {$company->name} (ID: {$company->id})");
            Log::info("   └─ Zones trouvées: {$company->deliveryZones->count()}");

            foreach ($company->deliveryZones as $zone) {
                Log::info("   📍 Zone: {$zone->name} (ID: {$zone->id})");
                Log::info("      └─ Ville BDD: " . ($zone->city ?? 'NON DÉFINIE'));
                Log::info("      └─ Centre: ({$zone->center_latitude}, {$zone->center_longitude})");

                // Pré-filtre distance : si le client a une position, ignorer les zones
                // trop éloignées (> 50 km) AVANT tout géocodage réseau. On évite ainsi
                // des appels Nominatim inutiles et on ne propose que la livraison locale.
                if ($latitude && $longitude && $zone->center_latitude && $zone->center_longitude) {
                    $preDistance = $this->calculateDistance(
                        $latitude, $longitude,
                        (float) $zone->center_latitude, (float) $zone->center_longitude
                    );
                    if ($preDistance > 50) {
                        Log::info("      └─ ⏭️ Zone ignorée (trop loin: " . round($preDistance, 1) . " km)");
                        continue;
                    }
                }

                // Ville de la zone : priorité à la valeur en base (rapide et fiable).
                // Le reverse-geocoding Nominatim (réseau, lent) n'est utilisé qu'en
                // dernier recours, uniquement si la zone n'a pas de ville en base.
                $zoneCityFromGeocode = $zone->city ?: $this->getCityFromCoordinates(
                    (float) $zone->center_latitude,
                    (float) $zone->center_longitude
                );
                Log::info("      └─ Ville zone (BDD/geocode): " . ($zoneCityFromGeocode ?? 'NON TROUVÉE'));

                // Filtrer par ville si le client a fourni une ville
                if ($normalizedClientCity) {
                    $normalizedZoneCity = $zoneCityFromGeocode ? $this->normalizeCity($zoneCityFromGeocode) : null;

                    if (!$normalizedZoneCity || !$this->citiesMatch($normalizedClientCity, $normalizedZoneCity)) {
                        Log::info("      └─ ❌ ZONE REJETÉE - Ville ne correspond pas");
                        Log::info("         Client: '{$normalizedClientCity}' vs Zone: '{$normalizedZoneCity}'");
                        continue;
                    } else {
                        Log::info("      └─ ✅ VILLE CORRESPOND: '{$normalizedClientCity}' ≈ '{$normalizedZoneCity}'");
                    }
                }

                $pricelist = $zone->activePricelist;
                if (!$pricelist) {
                    Log::warning("      └─ ⚠️ PAS DE PRICELIST ACTIF - Zone ignorée");
                    continue;
                }

                // Calculer le prix selon le type de pricing de l'entreprise
                $basePrice = $this->calculateDeliveryPrice($pricelist, $product, $latitude, $longitude, $zone);
                $assoCommission = (float) $pricelist->asso_commission;
                $price = $basePrice + $assoCommission;
                Log::info("      └─ Prix de base (livreur): {$basePrice} FCFA");
                Log::info("      └─ Commission ASSO: {$assoCommission} FCFA");
                Log::info("      └─ Prix total client: {$price} FCFA (Type: {$pricelist->pricing_type})");

                // Calculer la distance si les coordonnées du client sont fournies
                $distance = null;
                if ($latitude && $longitude && $zone->center_latitude && $zone->center_longitude) {
                    $distance = $this->calculateDistance(
                        $latitude, $longitude,
                        (float) $zone->center_latitude, (float) $zone->center_longitude
                    );
                    Log::info("      └─ Distance: " . round($distance, 2) . " km");
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
                    'city' => $zoneCityFromGeocode ?? $zone->city, // Utiliser la ville geocodée en priorité
                    'zone_latitude' => (float) $zone->center_latitude,
                    'zone_longitude' => (float) $zone->center_longitude,
                    'pricing_type' => $pricelist->pricing_type,
                    'delivery_price' => $price,
                    'formatted_delivery_price' => number_format($price, 0, ',', ' ') . ' FCFA',
                    'distance_km' => $distance !== null ? round($distance, 2) : null,
                    'deliverer' => $company->user ? [
                        'id' => $company->user->id,
                        'name' => $company->user->first_name . ' ' . $company->user->last_name,
                        'phone' => $company->user->phone,
                    ] : null,
                ];

                Log::info("      └─ ✅ Zone ajoutée aux résultats");
                Log::info('');
            }
        }

        // Trier par distance si disponible, sinon par prix
        if ($latitude && $longitude) {
            usort($partners, fn($a, $b) => ($a['distance_km'] ?? PHP_FLOAT_MAX) <=> ($b['distance_km'] ?? PHP_FLOAT_MAX));
            Log::info("🔄 Tri des partenaires: Par DISTANCE");
        } else {
            usort($partners, fn($a, $b) => $a['delivery_price'] <=> $b['delivery_price']);
            Log::info("🔄 Tri des partenaires: Par PRIX");
        }

        Log::info('');
        Log::info('✅ RÉSULTAT FINAL:');
        Log::info("   Total partenaires retournés: " . count($partners));
        if (!empty($partners)) {
            Log::info("   Partenaires:");
            foreach ($partners as $idx => $p) {
                Log::info("   " . ($idx + 1) . ". {$p['company_name']} - {$p['zone_name']} ({$p['city']}) - {$p['delivery_price']} FCFA");
            }
        } else {
            Log::warning("   ⚠️ Aucun partenaire ne correspond aux critères");
            if ($city) {
                Log::warning("   💡 Suggestion: Vérifier que des zones existent pour la ville '{$city}'");
            }
        }
        Log::info('═══════════════════════════════════════════════════════════════');
        Log::info('');

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

                // Prix du produit converti en XAF (devise pivot) au taux du MOMENT de la
                // commande. Le vendeur peut fixer son prix dans une autre devise ; toute la
                // chaîne aval (escrow, wallet, livraison, payin) reste en XAF. Erreur stricte
                // si aucun taux fiable : on ne devine jamais un montant à débiter.
                $sourceCurrency = strtoupper($product->currency ?? 'XAF');
                $sourceUnitPrice = (float) $product->price;
                if ($sourceCurrency === 'XAF') {
                    $unitPrice = $sourceUnitPrice;
                } else {
                    $conv = \App\Services\ExchangeRateService::convert($sourceCurrency, 'XAF', $sourceUnitPrice);
                    if (empty($conv['success']) || $conv['amount'] === null) {
                        throw new \Exception("Conversion {$sourceCurrency} → XAF indisponible pour '{$product->name}'. Réessayez plus tard.");
                    }
                    $unitPrice = round((float) $conv['amount'], 2);
                }

                $quantity = $item['quantity'];
                $totalPrice = $unitPrice * $quantity;
                $subtotal += $totalPrice;

                $orderItems[] = [
                    'product_id' => $product->id,
                    'seller_id' => $product->user_id,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,      // XAF (pivot)
                    'total_price' => $totalPrice,    // XAF (pivot)
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
            $baseDeliveryPrice = $this->calculateDeliveryPrice($pricelist, $firstProduct, $deliveryLatitude, $deliveryLongitude, $zone);
            $assoCommission = (float) $pricelist->asso_commission;
            $deliveryFee = $baseDeliveryPrice + $assoCommission;

            Log::info("[OrderService] Frais de livraison calculés", [
                'base_delivery_price' => $baseDeliveryPrice,
                'asso_commission' => $assoCommission,
                'total_delivery_fee' => $deliveryFee,
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

            // 4. Créer la commande
            $order = Order::create([
                'user_id' => $client->id,
                'status' => 'pending',
                'subtotal' => $subtotal,
                'delivery_fee' => $deliveryFee,
                'base_delivery_price' => $baseDeliveryPrice,
                'delivery_commission' => $assoCommission,
                'total' => $total,
                'delivery_address' => $deliveryAddress,
                'delivery_latitude' => $deliveryLatitude,
                'delivery_longitude' => $deliveryLongitude,
                'delivery_company_id' => $deliveryCompanyId,
                'delivery_zone_id' => $deliveryZoneId,
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
        ?string $notes = null
    ): Order {
        return DB::transaction(function () use (
            $client, $items, $shippingOptionId, $shippingWeightKg, $shippingCbm,
            $deliveryAddress, $paymentMode, $kpayProvider, $kpayPhone, $notes
        ) {
            $subtotal = 0;
            $orderItems = [];
            $countryCode = null;
            $calculatedWeightKg = 0;
            $hasMissingWeight = false;

            foreach ($items as $item) {
                $product = Product::lockForUpdate()->findOrFail($item['product_id']);
                if (!$product->is_wholesale || $product->status !== 'active') {
                    throw new \Exception("Le produit '{$product->name}' n'est pas disponible en gros.");
                }

                $tier = ProductPriceTier::where('product_id', $product->id)
                    ->where('id', $item['price_tier_id'])
                    ->where('is_active', true)
                    ->firstOrFail();

                $quantity = (int) $item['quantity'];
                if ($quantity < $tier->min_quantity) {
                    throw new \Exception("Quantité minimale non atteinte pour '{$product->name}' ({$tier->label}) : minimum {$tier->min_quantity}.");
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
                if (is_numeric($product->weight) && (float) $product->weight > 0) {
                    $calculatedWeightKg += (float) $product->weight * $quantity;
                } else {
                    $hasMissingWeight = true;
                }

                $orderItems[] = [
                    'product_id' => $product->id,
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
            $total = $subtotal + $shippingCost;

            $isDirect = in_array($paymentMode, ['kpay_direct', 'stripe_direct']);

            // Mode wallet : escrow depuis le solde. Modes directs : encaissement externe.
            if (!$isDirect) {
                $this->walletService->lockFunds(
                    $client, $total, 'Escrow commande gros - En attente de validation vendeur',
                    'order', null, ['wholesale' => true], $kpayProvider ?? 'kpay'
                );
            }

            $order = Order::create([
                'user_id' => $client->id,
                'status' => 'pending',
                'is_wholesale' => true,
                'import_country_code' => $countryCode,
                'shipping_mode' => $shipping->mode,
                'shipping_option_id' => $shipping->id,
                'subtotal' => $subtotal,
                'delivery_fee' => $shippingCost, // coût d'expédition internationale
                'base_delivery_price' => $shippingCost,
                'delivery_commission' => 0,
                'total' => $total,
                'delivery_address' => $deliveryAddress,
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

            // Paiement (réutilise la logique des rails directs). Pour la carte native,
            // pose les attributs transitoires client_secret / payment_intent_id sur $order.
            $this->initiateDirectPayment($order, $total, $paymentMode, $kpayProvider, $kpayPhone);

            $this->fcmService->sendToUser(
                $client,
                'Commande en gros créée',
                "Votre commande gros #{$order->order_number} a été créée. En attente de paiement/validation.",
                ['type' => 'wholesale_order_created', 'order_id' => (string) $order->id, 'order_number' => $order->order_number]
            );

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

        DB::transaction(function () use ($order, &$sellers) {
            $order = Order::whereKey($order->id)->lockForUpdate()->with('items')->first();
            if (!$order || $order->payment_status === 'paid') {
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
                if ($item->product && $item->product->stock !== null) {
                    $item->product->increment('stock', $item->quantity);
                }
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

        DB::transaction(function () use ($order, &$sellers) {
            $order = Order::whereKey($order->id)->lockForUpdate()->with('items')->first();
            if (!$order || $order->payment_status === 'paid') {
                return;
            }

            $order->update(['payment_status' => 'paid']);
            $sellers = $order->items->pluck('seller_id')->unique()->values()->all();

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
                    'payment_method' => 'stripe_direct',
                    'payment_reference' => $order->payment_reference,
                    'subtotal' => (float) $order->subtotal,
                    'delivery_fee' => (float) $order->delivery_fee,
                ],
                'status' => 'completed',
                'provider' => 'stripe',
            ]);

            Log::info('[OrderService] Commande Stripe confirmée (payée)', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
            ]);
        });

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
                if ($item->product && $item->product->stock !== null) {
                    $item->product->increment('stock', $item->quantity);
                }
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

    /**
     * Récupère le nom de la ville depuis les coordonnées GPS via reverse geocoding (Nominatim).
     */
    private function getCityFromCoordinates(float $lat, float $lon): ?string
    {
        // Cache par centre de zone (arrondi ~11 m) pour éviter de rappeler
        // Nominatim à chaque requête : le résultat est stable dans le temps.
        $cacheKey = 'geocode_city_' . round($lat, 4) . '_' . round($lon, 4);

        return \Illuminate\Support\Facades\Cache::remember($cacheKey, now()->addDays(30), function () use ($lat, $lon) {
            return $this->fetchCityFromNominatim($lat, $lon);
        });
    }

    /**
     * Appel réseau brut vers Nominatim (isolé pour permettre la mise en cache).
     */
    private function fetchCityFromNominatim(float $lat, float $lon): ?string
    {
        try {
            $response = \Illuminate\Support\Facades\Http::timeout(5)
                ->withHeaders([
                    'User-Agent' => 'AssoApp/1.0'
                ])
                ->get('https://nominatim.openstreetmap.org/reverse', [
                    'lat' => $lat,
                    'lon' => $lon,
                    'format' => 'json',
                    'addressdetails' => 1,
                    'zoom' => 10,
                ]);

            if ($response->successful()) {
                $data = $response->json();
                $address = $data['address'] ?? [];

                // Essayer différents champs pour extraire la ville
                $cityFields = [
                    'city',
                    'town',
                    'municipality',
                    'village',
                    'state_district',
                    'state',
                    'county'
                ];

                foreach ($cityFields as $field) {
                    if (!empty($address[$field])) {
                        return $address[$field];
                    }
                }

                // Fallback: utiliser le display_name et extraire la première partie
                if (!empty($data['display_name'])) {
                    $parts = explode(',', $data['display_name']);
                    return trim($parts[0]);
                }
            }

            return null;

        } catch (\Exception $e) {
            Log::error("❌ Erreur reverse geocoding: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Normalise un nom de ville pour comparaison (enlève accents, casse, espaces).
     */
    private function normalizeCity(?string $city): ?string
    {
        if (!$city) {
            return null;
        }

        // Enlever les accents
        $city = iconv('UTF-8', 'ASCII//TRANSLIT', $city);

        // Minuscules
        $city = strtolower($city);

        // Enlever les espaces et caractères spéciaux
        $city = preg_replace('/[^a-z0-9]/', '', $city);

        return $city;
    }

    /**
     * Compare deux villes de manière intelligente (tolère accents, variantes, etc.).
     */
    private function citiesMatch(string $city1, string $city2): bool
    {
        // Si égalité stricte
        if ($city1 === $city2) {
            return true;
        }

        // Si l'une contient l'autre (ex: "Yaounde" dans "Communaute urbaine de Yaounde")
        if (str_contains($city1, $city2) || str_contains($city2, $city1)) {
            return true;
        }

        // Si les 5 premiers caractères matchent (pour gérer "Yaounde" vs "Yaoundé")
        if (strlen($city1) >= 5 && strlen($city2) >= 5) {
            if (substr($city1, 0, 5) === substr($city2, 0, 5)) {
                return true;
            }
        }

        return false;
    }
}
