<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ImportCountry;
use App\Models\ImportShippingOption;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVideo;
use App\Services\ImportFreightService;
use App\Services\OrderService;
use App\Services\PaymentMethodService;
use App\Services\ExchangeRateService;
use App\Services\ProductVariantService;
use App\Support\ImportHub;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * Module ASSO CHINA / DUBAÏ / TURQUIE — catalogue de commande EN GROS (import).
 *
 * Chaque pays a sa rubrique. Les produits « gros » exposent leurs paliers de prix
 * (conditionnement + cota) et les options d'expédition internationale.
 */
class ImportController extends Controller
{
    public const PER_PAGE = 20;
    public const MAX_PER_PAGE = 50;

    /** Règles déjà lues : le catalogue sert plusieurs produits. */
    private ?array $freightRules = null;

    /**
     * Catalogue gros d'un pays d'import, page par page.
     * GET /v1/import/{code}/products?page=1&per_page=20
     *
     * Sans `page` (versions de l'app d'avant la pagination) : catalogue complet.
     */
    public function products(Request $request, string $code)
    {
        $request->validate([
            'category_id' => 'nullable|integer|exists:categories,id',
            'subcategory_id' => 'nullable|integer|exists:subcategories,id',
        ]);
        $code = strtoupper($code);
        $targetCurrency = $this->targetCurrency($request);
        $country = ImportCountry::where('code', $code)->where('is_active', true)->firstOrFail();

        $query = $this->catalogQuery($request->input('q'))
            ->where('origin_country', $code)
            ->when($request->filled('category_id'), fn ($q) => $q->where('category_id', $request->integer('category_id')))
            ->when($request->filled('subcategory_id'), fn ($q) => $q->where('subcategory_id', $request->integer('subcategory_id')))
            ->with(['priceTiers', 'primaryImage', 'images', 'variants', 'video', 'shop', 'category', 'subcategory'])
            // Ordre stable d'une page à l'autre, même pour des produits créés à la même seconde.
            ->latest()
            ->orderByDesc('id');

        $page = null;
        if ($request->filled('page')) {
            $perPage = min(self::MAX_PER_PAGE, max(1, (int) $request->input('per_page', self::PER_PAGE)));
            $page = $query->paginate($perPage);
            $items = $page->getCollection();
        } else {
            $items = $query->get();
        }
        $products = $items->map(fn (Product $p) => $this->serializeProduct($p, false, $targetCurrency))->values();

        $shippingOptions = ImportShippingOption::activeForCountry($code)
            ->get()
            ->map(fn (ImportShippingOption $option) => $this->serializeShippingOption($option, $targetCurrency));

        return response()->json([
            'success' => true,
            'country' => ['code' => $country->code, 'name' => $country->name, 'flag' => $country->flag],
            'products' => $products,
            'pagination' => $page ? [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'has_more' => $page->hasMorePages(),
            ] : null,
            'currency' => $targetCurrency,
            'shipping_options' => $shippingOptions,
        ]);
    }

    /**
     * Nombre de produits importés correspondant à une recherche, pour chaque pays actif
     * (l'app affiche « Turquie · 3 » sur les onglets pays).
     * GET /v1/import/search?q=chargeur
     */
    public function searchCounts(Request $request)
    {
        $request->validate(['q' => 'required|string|max:100']);
        $counts = $this->catalogQuery($request->input('q'))
            ->whereIn('origin_country', ImportCountry::where('is_active', true)->pluck('code'))
            ->selectRaw('origin_country, count(*) as total')
            ->groupBy('origin_country')
            ->pluck('total', 'origin_country')
            ->map(fn ($n) => (int) $n);

        return response()->json(['success' => true, 'q' => $request->input('q'), 'counts' => $counts]);
    }

    /** Produits gros actifs, filtrés par la recherche (nom, description, caractéristiques). */
    private function catalogQuery(?string $search)
    {
        $query = Product::query()
            ->where('is_wholesale', true)
            ->where('status', 'active')
            ->whereHas('shop', fn ($q) => $q->publiclyVisible());

        app(\App\Services\ProductSearchService::class)->apply($query, trim((string) $search));

        return $query;
    }

    /**
     * Détail d'un produit gros (avec paliers).
     * GET /v1/import/products/{id}
     */
    public function show(Request $request, int $id)
    {
        $product = Product::where('is_wholesale', true)
            ->where('status', 'active')
            ->whereHas('shop', fn ($query) => $query->publiclyVisible())
            ->with(['priceTiers', 'images', 'variants', 'video', 'shop', 'category', 'subcategory'])
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'product' => $this->serializeProduct($product, true, $this->targetCurrency($request)),
            'shipping_options' => ImportShippingOption::activeForCountry($product->origin_country)
                ->get()->map(fn (ImportShippingOption $option) => $this->serializeShippingOption($option, $this->targetCurrency($request))),
        ]);
    }

    /** Serve the primary product image through the API for Flutter Web/CORS. */
    public function image(int $id)
    {
        $product = Product::where('is_wholesale', true)
            ->with('primaryImage')
            ->findOrFail($id);

        $path = $product->primaryImage?->image_path;
        if (!$path) {
            abort(404);
        }

        $relativePath = ltrim($path, '/');
        if (str_starts_with($relativePath, 'storage/')) {
            $relativePath = substr($relativePath, strlen('storage/'));
        }

        if (!Storage::disk('public')->exists($relativePath)) {
            abort(404);
        }

        return response()->file(Storage::disk('public')->path($relativePath), [
            'Access-Control-Allow-Origin' => '*',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }

    /** Sert chaque photo d'un produit importé via l'API (compatible mobile/web et CORS). */
    public function productImage(ProductImage $image)
    {
        $image->loadMissing('product');
        abort_unless($image->product?->is_wholesale, 404);

        $relativePath = ltrim($image->image_path, '/');
        if (str_starts_with($relativePath, 'storage/')) {
            $relativePath = substr($relativePath, strlen('storage/'));
        }
        abort_unless(Storage::disk('public')->exists($relativePath), 404);

        return response()->file(Storage::disk('public')->path($relativePath), [
            'Access-Control-Allow-Origin' => '*',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }

    /**
     * Fichiers d'une vidéo produit : `video` (fiche), `preview` (cartes), `poster`.
     * GET /v1/import/videos/{video}/{kind}
     *
     * `response()->file()` répond aux requêtes partielles (206 + Content-Range) :
     * le lecteur iOS n'ouvre pas une vidéo servie autrement, et Android s'en sert
     * pour démarrer sans tout télécharger.
     */
    public function video(ProductVideo $video, string $kind)
    {
        $video->loadMissing('product');
        abort_unless($video->product && $video->isReady(), 404);

        $path = $video->absolutePath($kind);
        abort_unless($path, 404);

        $mime = match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'mov' => 'video/quicktime',
            'webm' => 'video/webm',
            '3gp' => 'video/3gpp',
            'mkv' => 'video/x-matroska',
            default => 'video/mp4',
        };

        return response()->file($path, [
            'Content-Type' => $mime,
            'Access-Control-Allow-Origin' => '*',
            // Chaque vidéo a sa propre URL (id) : la remplacer change l'URL.
            'Cache-Control' => 'public, max-age=604800',
        ]);
    }

    /**
     * Options d'expédition internationale d'un pays.
     * GET /v1/import/{code}/shipping
     */
    public function shipping(string $code)
    {
        return response()->json([
            'success' => true,
            'shipping_options' => ImportShippingOption::activeForCountry(strtoupper($code))->get()->map->toApi(),
        ]);
    }

    /**
     * Créer une commande EN GROS.
     * POST /v1/import/orders  (auth)
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            // Indicatif : le serveur applique le palier que la quantité atteint.
            'items.*.price_tier_id' => 'nullable|exists:product_price_tiers,id',
            'items.*.variant_id' => 'nullable|integer|exists:product_variants,id',
            'items.*.quantity' => 'required|integer|min:1',
            'shipping_option_id' => 'required|exists:import_shipping_options,id',
            // Envoyés par les anciens APK, ignorés : le serveur calcule seul le transport.
            'shipping_weight_kg' => 'nullable|numeric|min:0',
            'shipping_cbm' => 'nullable|numeric|min:0',
            // Retrait à l'entrepôt : ni SOLEX ni adresse de livraison.
            'warehouse_pickup' => 'nullable|boolean',
            'payment_mode' => 'nullable|in:wallet,kpay_direct,stripe_direct',
            'provider' => 'required_if:payment_mode,kpay_direct|string',
            'phone_number' => 'required_if:payment_mode,kpay_direct|string',
            // Livraison SOLEX de Douala jusqu'au client (offre de GET /v1/delivery/partners).
            'delivery_company_id' => [Rule::requiredIf(! $request->boolean('warehouse_pickup')), 'nullable', 'exists:deliverer_companies,id'],
            'delivery_zone_id' => 'nullable|exists:delivery_zones,id',
            'delivery_route_id' => 'nullable|exists:delivery_routes,id',
            'delivery_grid_id' => 'nullable|exists:delivery_city_grids,id',
            'delivery_vehicle' => 'nullable|string|max:30',
            'delivery_quarter' => 'nullable|string|max:120',
            'delivery_city' => 'nullable|string|max:120',
            'delivery_country' => 'nullable|string|max:60',
            'delivery_address' => [Rule::requiredIf(! $request->boolean('warehouse_pickup')), 'nullable', 'string'],
            'delivery_address_details' => 'nullable|string|max:500',
            'customer_phone' => 'required|string|max:30',
            'delivery_latitude' => 'nullable|numeric',
            'delivery_longitude' => 'nullable|numeric',
            'notes' => 'nullable|string',
        ], [
            'delivery_company_id.required' => __('orders.wholesale.solex_delivery_required'),
            'delivery_address.required' => __('orders.wholesale.delivery_address_required'),
        ]);

        $paymentMode = $validated['payment_mode'] ?? 'kpay_direct';

        // Garde-fou : le rail carte (Stripe natif) n'est proposé que s'il est fonctionnel.
        if ($paymentMode === 'stripe_direct' && !PaymentMethodService::isEnabled('stripe')) {
            return response()->json([
                'success' => false,
                'message' => __('payments.stripe_unavailable'),
            ], 422);
        }

        try {
            $order = app(OrderService::class)->createWholesaleOrder(
                client: $request->user(),
                items: $validated['items'],
                shippingOptionId: (int) $validated['shipping_option_id'],
                deliveryAddress: $validated['delivery_address'] ?? null,
                paymentMode: $paymentMode,
                kpayProvider: $validated['provider'] ?? null,
                kpayPhone: $validated['phone_number'] ?? null,
                notes: $validated['notes'] ?? null,
                delivery: [
                    'warehouse_pickup' => $request->boolean('warehouse_pickup'),
                    'company_id' => (int) ($validated['delivery_company_id'] ?? 0),
                    'zone_id' => isset($validated['delivery_zone_id']) ? (int) $validated['delivery_zone_id'] : null,
                    'route_id' => isset($validated['delivery_route_id']) ? (int) $validated['delivery_route_id'] : null,
                    'grid_id' => isset($validated['delivery_grid_id']) ? (int) $validated['delivery_grid_id'] : null,
                    'vehicle' => $validated['delivery_vehicle'] ?? null,
                    'quarter' => $validated['delivery_quarter'] ?? null,
                    'city' => $validated['delivery_city'] ?? null,
                    'country' => $validated['delivery_country'] ?? null,
                    'latitude' => isset($validated['delivery_latitude']) ? (float) $validated['delivery_latitude'] : null,
                    'longitude' => isset($validated['delivery_longitude']) ? (float) $validated['delivery_longitude'] : null,
                    'address_details' => $validated['delivery_address_details'] ?? null,
                    'customer_phone' => $validated['customer_phone'],
                ],
            );

            return response()->json([
                'success' => true,
                'message' => __('orders.wholesale.created'),
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'payment_reference' => $order->payment_reference,
                // Carte native (stripe_direct) : confirmation via Payment Sheet, puis polling.
                'client_secret' => $paymentMode === 'stripe_direct' ? ($order->client_secret ?? null) : null,
                'payment_intent_id' => $paymentMode === 'stripe_direct' ? ($order->payment_intent_id ?? null) : null,
                'publishable_key' => $paymentMode === 'stripe_direct' ? ($order->stripe_publishable_key ?? null) : null,
            ], 201);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    /** Sérialisation d'un produit gros avec ses paliers et sa quantité minimale. */
    private function serializeProduct(Product $p, bool $full = false, string $targetCurrency = 'XAF'): array
    {
        $tiers = $p->relationLoaded('priceTiers') ? $p->priceTiers : collect();
        // Quantité minimale « effective » = plus petit cota parmi les paliers (repli sur le champ produit).
        $minFromTiers = $tiers->min('min_quantity');

        $data = [
            'id' => $p->id,
            'name' => $p->name,
            'description' => $p->description,
            'characteristics' => $p->characteristics,
            'commercial_information' => $p->commercial_information,
            'origin_country' => $p->origin_country,
            'currency' => $p->currency,
            'min_order_quantity' => $minFromTiers ?? $p->min_order_quantity,
            // Le poids est renseigné par l'équipe/le vendeur, jamais par le client.
            'unit_weight_kg' => $p->weightKg(),
            'freight_rules' => $this->freightRules(),
            // Dimensions du colis d'une unité, facultatives (cm).
            'length_cm' => $p->length_cm !== null ? (float) $p->length_cm : null,
            'width_cm' => $p->width_cm !== null ? (float) $p->width_cm : null,
            'height_cm' => $p->height_cm !== null ? (float) $p->height_cm : null,
            // Volume d'un carton (CBM) ; chaque palier peut avoir le sien (price_tiers[].cbm).
            'cbm' => $p->cbm !== null ? (float) $p->cbm : null,
            'stock' => $p->stock,
            'requires_quote' => (bool) $p->requires_quote,
            'price_is_indicative' => (bool) $p->requires_quote,
            'availability_label' => $p->requires_quote ? __('Prix et disponibilité à confirmer') : null,
            'category_id' => $p->category_id,
            'subcategory_id' => $p->subcategory_id,
            'category' => $p->category ? ['id' => $p->category->id, 'name' => $p->category->name] : null,
            'subcategory' => $p->subcategory ? ['id' => $p->subcategory->id, 'name' => $p->subcategory->name] : null,
            // Livraison gratuite offerte par le vendeur : course SOLEX offerte.
            'free_delivery' => $p->hasFreeDelivery(),
            // Vente avec acompte (deposit_enabled, deposit_rate).
            ...\App\Services\DepositOrderService::productInfo($p),
            'variants' => ($p->relationLoaded('variants') ? $p->variants : collect())
                ->where('is_active', true)
                ->map(fn ($variant) => app(ProductVariantService::class)->presentVariant($variant, $p))->values(),
            'variant_options' => $p->relationLoaded('variants')
                ? app(ProductVariantService::class)->presentOptions($p)
                : [],
            // Les options se cumulent-elles pour atteindre un palier ?
            'tier_mix_variants' => (bool) ($p->tier_mix_variants ?? true),
            'price_tiers' => $tiers->sortBy('min_quantity')->map(fn ($tier) => $this->serializeTier($tier, $targetCurrency))->values(),
            'image' => $p->id
                ? url('/api/v1/import/products/' . $p->id . '/image')
                : null,
            'images' => ($p->relationLoaded('images') ? $p->images : collect())
                ->map(fn ($i) => url('/api/v1/import/product-images/' . $i->id))->values(),
            // Vidéo de présentation, seulement une fois prête (null sinon).
            'video' => $p->relationLoaded('video') ? $p->video?->toApi() : null,
        ];

        if ($full) {
            // Les images sont déjà exposées dans la liste pour permettre la galerie mobile.
            // Délai de livraison annoncé (jours ouvrables) : produit → catégorie → défaut.
            $data['delivery_delay'] = \App\Support\DeliveryDelay::forProduct($p);
        }

        return $data;
    }

    /** Règles du transport, pour que l'application affiche ce que le serveur appliquera. */
    private function freightRules(): array
    {
        return $this->freightRules ??= [
            'air_max_weight_kg' => ImportFreightService::airMaxWeightKg(),
            // Bateau : CBM d'un carton × cartons entiers (quantité / pack_size) × tarif.
            'sea_billing' => 'cbm_per_carton',
            'pickup_threshold_kg' => ImportHub::pickupThresholdKg(),
            'warehouse' => ImportHub::pickupSnapshot()['warehouse'],
        ];
    }

    private function targetCurrency(Request $request): string
    {
        $currency = strtoupper(trim((string) $request->query('currency', 'XAF')));
        return preg_match('/^[A-Z]{3}$/', $currency) ? $currency : 'XAF';
    }

    private function convertAmount(float $amount, string $from, string $to): ?float
    {
        $result = ExchangeRateService::convert(strtoupper($from), strtoupper($to), $amount);
        return !empty($result['success']) ? (float) $result['amount'] : null;
    }

    private function serializeTier($tier, string $targetCurrency): array
    {
        $sourceCurrency = strtoupper($tier->currency ?? 'XAF');
        $sourceAmount = (float) $tier->unit_price;
        $amountXaf = $this->convertAmount($sourceAmount, $sourceCurrency, 'XAF');
        $displayAmount = $this->convertAmount($sourceAmount, $sourceCurrency, $targetCurrency);

        // Si la devise choisie est momentanément indisponible, on expose le prix
        // d'origine au lieu d'afficher un montant inventé.
        $effectiveCurrency = $displayAmount === null ? $sourceCurrency : $targetCurrency;
        $effectiveAmount = $displayAmount ?? $sourceAmount;

        return array_merge($tier->toApi(), [
            'unit_price' => $effectiveAmount,
            'unit_price_xaf' => $amountXaf ?? ($sourceCurrency === 'XAF' ? $sourceAmount : 0),
            'currency' => $effectiveCurrency,
            'formatted_price' => number_format($effectiveAmount, 2, ',', ' ') . ' ' . $effectiveCurrency,
        ]);
    }

    private function serializeShippingOption(ImportShippingOption $option, string $targetCurrency): array
    {
        $data = $option->toApi();
        $sourceCurrency = strtoupper($option->currency ?? 'XAF');
        $sourceAmount = (float) $option->rate_amount;
        $amountXaf = $this->convertAmount($sourceAmount, $sourceCurrency, 'XAF');
        $displayAmount = $this->convertAmount($sourceAmount, $sourceCurrency, $targetCurrency);
        $effectiveCurrency = $displayAmount === null ? $sourceCurrency : $targetCurrency;
        $effectiveAmount = $displayAmount ?? $sourceAmount;
        $suffix = match ($option->rate_type) { 'per_kg' => ' / kg', 'per_cbm' => ' / CBM', default => '' };

        return array_merge($data, [
            'rate_amount' => $effectiveAmount,
            'rate_amount_xaf' => $amountXaf ?? ($sourceCurrency === 'XAF' ? $sourceAmount : 0),
            'currency' => $effectiveCurrency,
            'formatted_rate' => number_format($effectiveAmount, 2, ',', ' ') . ' ' . $effectiveCurrency . $suffix,
        ]);
    }

    private function imageUrl(?string $path): ?string
    {
        return media_url($path);
    }
}
