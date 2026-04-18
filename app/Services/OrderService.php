<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
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

                // Geocoder les coordonnées de la zone pour obtenir la ville
                $zoneCityFromGeocode = $this->getCityFromCoordinates(
                    (float) $zone->center_latitude,
                    (float) $zone->center_longitude
                );
                Log::info("      └─ Ville geocodée: " . ($zoneCityFromGeocode ?? 'NON TROUVÉE'));

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
                $price = $this->calculateDeliveryPrice($pricelist, $product, $latitude, $longitude, $zone);
                Log::info("      └─ Prix calculé: {$price} FCFA (Type: {$pricelist->pricing_type})");

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
                    'distance_km' => $distance ? round($distance, 2) : null,
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
        ?string $notes = null
    ): Order {
        return DB::transaction(function () use (
            $client, $items, $deliveryCompanyId, $deliveryZoneId, $walletProvider,
            $deliveryAddress, $deliveryLatitude, $deliveryLongitude, $notes
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

            // 3. Verrouiller les fonds du client (escrow)
            $this->walletService->lockFunds(
                $client,
                $total,
                "Escrow commande - En attente de validation vendeur",
                'order',
                null, // L'ID de l'order sera mis à jour après création
                ['subtotal' => $subtotal, 'delivery_fee' => $deliveryFee],
                $walletProvider
            );

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
                'payment_method' => 'wallet_' . $walletProvider,
                'payment_status' => 'paid',
                'notes' => $notes,
            ]);

            // Créer les items
            foreach ($orderItems as $itemData) {
                $order->items()->create($itemData);
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
        try {
            $response = \Illuminate\Support\Facades\Http::timeout(10)
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
