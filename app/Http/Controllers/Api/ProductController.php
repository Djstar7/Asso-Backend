<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Category;
use App\Models\DeliveryPricelist;
use App\Models\Inventory;
use App\Services\ProductBroadcastService;
use App\Services\ProductVariantService;
use Illuminate\Http\Request;
use Illuminate\Database\UniqueConstraintViolationException;

class ProductController extends Controller
{
    /**
     * List products with pagination, filters, search
     */
    public function index(Request $request)
    {
        $query = Product::with(['images', 'primaryImage', 'category', 'subcategory', 'shop', 'user', 'variants'])
            ->where('status', 'active')
            // Catalogue local par défaut ; les produits importés restent accessibles via ?origin_country=XX.
            ->when(!$request->filled('origin_country'), fn ($query) => $query->where(function ($q) {
                $q->whereNull('origin_country')
                    ->orWhere('origin_country', '');
            }))
            ->whereHas('shop', function ($q) {
                $q->where('status', 'active');
            });

        // Search (accent-insensitive using PostgreSQL f_unaccent function)
        if ($request->has('search') && $request->search) {
            $search = $request->search;

            $query->where(function ($q) use ($search) {
                // Use PostgreSQL f_unaccent function for accent-insensitive search
                $q->whereRaw('f_unaccent(name) ILIKE ?', ['%' . $search . '%'])
                    ->orWhereRaw('f_unaccent(description) ILIKE ?', ['%' . $search . '%']);
            });
        }

        // Filter by category
        if ($request->has('category_id') && $request->category_id) {
            $query->where('category_id', $request->category_id);
        }

        // Filter by subcategory
        if ($request->has('subcategory_id') && $request->subcategory_id) {
            $query->where('subcategory_id', $request->subcategory_id);
        }

        // Filter by shop
        if ($request->has('shop_id') && $request->shop_id) {
            $query->where('shop_id', $request->shop_id);
        }

        // Filter by type (service/article)
        if ($request->has('type') && $request->type) {
            $query->where('type', $request->type);
        }

        // Filter by origin country (produits importés : CN, TR, AE…)
        if ($request->filled('origin_country')) {
            $query->where('origin_country', strtoupper($request->origin_country));
        }

        // Filter by price range — sur price_xaf (canonique XAF), car les prix peuvent
        // désormais être fixés dans différentes devises. Fallback sur price si price_xaf null.
        if ($request->has('min_price') && $request->min_price) {
            $query->whereRaw('COALESCE(price_xaf, price) >= ?', [$request->min_price]);
        }
        if ($request->has('max_price') && $request->max_price) {
            $query->whereRaw('COALESCE(price_xaf, price) <= ?', [$request->max_price]);
        }

        // Sort — trier par prix se fait sur la valeur canonique XAF (price_xaf)
        $sortBy = $request->get('sort_by', 'created_at');
        $sortOrder = $request->get('sort_order', 'desc');
        if ($sortBy === 'price') {
            $query->orderByRaw('COALESCE(price_xaf, price) ' . ($sortOrder === 'asc' ? 'asc' : 'desc'));
        } else {
            $query->orderBy($sortBy, $sortOrder);
        }

        // Paginate
        $perPage = $request->get('per_page', 20);
        $products = $query->paginate($perPage);

        // Check favorites if authenticated
        $favoriteIds = [];
        if ($request->user()) {
            $favoriteIds = \DB::table('favorites')
                ->where('user_id', $request->user()->id)
                ->pluck('product_id')
                ->toArray();
        }

        $productsData = $products->getCollection()->map(function ($product) use ($favoriteIds) {
            return $this->formatProduct($product, $favoriteIds);
        });

        // Asso Ads : les annonces s'intercalent dans la première page seulement —
        // au-delà, l'utilisateur fait défiler un résultat, pas une vitrine.
        if ($products->currentPage() === 1) {
            $productsData = $this->injectSponsored($productsData, $favoriteIds, [
                'category_id' => $request->get('category_id'),
                'subcategory_id' => $request->get('subcategory_id'),
                'type' => $request->get('type'),
                'origin_country' => $request->get('origin_country'),
            ]);
        }

        return response()->json([
            'success' => true,
            'products' => $productsData,
            'pagination' => [
                'current_page' => $products->currentPage(),
                'last_page' => $products->lastPage(),
                'per_page' => $products->perPage(),
                'total' => $products->total(),
                'has_more' => $products->hasMorePages(),
            ],
        ]);
    }

    /**
     * Asso Ads — intercale les produits sponsorisés dans une page de résultats.
     *
     * Les annonces sont insérées à des positions fixes (réglables en admin) plutôt
     * qu'ajoutées en tête : une publicité reconnaissable, au milieu du flux, se lit
     * mieux qu'un bloc de trois annonces qui repousse le contenu réel.
     *
     * Chaque carte réellement insérée consomme une impression du quota acheté :
     * c'est le seul endroit où la facturation est décomptée.
     *
     * @param  \Illuminate\Support\Collection  $productsData  cartes déjà formatées
     * @return \Illuminate\Support\Collection
     */
    private function injectSponsored($productsData, array $favoriteIds, array $filters = [])
    {
        $boostService = app(\App\Services\ProductBoostService::class);

        $slots = $boostService->slotsPerPage();
        if ($slots <= 0) {
            return $productsData;
        }

        $boosts = $boostService->pickForFeed(
            $slots,
            $productsData->pluck('id')->filter()->all(),
            $filters
        );

        if ($boosts->isEmpty()) {
            return $productsData;
        }

        $items = $productsData->values()->all();
        $positions = $boostService->slotPositions();
        $served = [];

        foreach ($boosts as $index => $boost) {
            if (!$boost->product) {
                continue;
            }

            $card = $this->formatProduct($boost->product, $favoriteIds);
            $card['is_sponsored'] = true;
            $card['sponsored_label'] = __('products.sponsored_label');
            $card['boost_id'] = $boost->id;

            // Position prévue, ou à la suite si la page est plus courte que le slot.
            $at = $positions[$index] ?? count($items);
            $at = min($at, count($items));

            array_splice($items, $at, 0, [$card]);
            $served[] = $boost;
        }

        // Décompté après insertion : on ne facture que ce qui part réellement.
        $boostService->consume($served);

        return collect($items);
    }

    /**
     * Get nearby products (6 items)
     * Returns products close to user location
     */
    public function nearby(Request $request)
    {
        $limit = $request->input('limit', 6);

        // Get user's favorite IDs if authenticated
        $favoriteIds = $request->user()
            ? $request->user()->favorites()->pluck('product_id')->toArray()
            : [];

        // For now, return random active products
        // TODO: Implement actual location-based filtering when location data is available
        $products = Product::with(['images', 'primaryImage', 'category', 'subcategory', 'shop', 'user', 'variants'])
            ->where('status', 'active')
            ->whereHas('shop', function ($q) {
                $q->where('status', 'active');
            })
            ->inRandomOrder()
            ->limit($limit)
            ->get();

        return response()->json([
            'success' => true,
            'products' => $products->map(fn($product) => $this->formatProduct($product, $favoriteIds)),
        ]);
    }

    /**
     * Get recent products (6 items)
     * Returns recently posted products
     */
    public function recent(Request $request)
    {
        $limit = $request->input('limit', 6);

        // Get user's favorite IDs if authenticated
        $favoriteIds = $request->user()
            ? $request->user()->favorites()->pluck('product_id')->toArray()
            : [];

        $products = Product::with(['images', 'primaryImage', 'category', 'subcategory', 'shop', 'user', 'variants'])
            ->where('status', 'active')
            ->where(function ($q) {
                $q->whereNull('origin_country')
                    ->orWhere('origin_country', '');
            })
            ->whereHas('shop', function ($q) {
                $q->where('status', 'active');
            })
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();

        return response()->json([
            'success' => true,
            'products' => $products->map(fn($product) => $this->formatProduct($product, $favoriteIds)),
        ]);
    }

    /**
     * Show a single product
     */
    public function show(Request $request, $id)
    {
        $product = Product::with(['images', 'primaryImage', 'category', 'subcategory', 'shop', 'user', 'reviews.user', 'variants'])
            ->where('status', 'active')
            ->where(function ($q) {
                $q->whereNull('origin_country')
                    ->orWhere('origin_country', '');
            })
            ->whereHas('shop', fn ($query) => $query->where('status', 'active'))
            ->findOrFail($id);

        $favoriteIds = [];
        if ($request->user()) {
            $favoriteIds = \DB::table('favorites')
                ->where('user_id', $request->user()->id)
                ->pluck('product_id')
                ->toArray();
        }

        // Asso Ads : ouverture venue d'une carte sponsorisée. Le clic ne consomme
        // pas de quota (seule l'impression est facturée), il mesure l'efficacité
        // de la campagne pour le vendeur.
        if ($request->boolean('from_ad')) {
            app(\App\Services\ProductBoostService::class)->recordClick($product);
        }

        return response()->json([
            'success' => true,
            'product' => $this->formatProduct($product, $favoriteIds, true),
        ]);
    }

    /**
     * Toggle favorite
     */
    public function toggleFavorite(Request $request, $id)
    {
        $user = $request->user();
        $product = Product::findOrFail($id);

        $exists = \DB::table('favorites')
            ->where('user_id', $user->id)
            ->where('product_id', $product->id)
            ->exists();

        if ($exists) {
            \DB::table('favorites')
                ->where('user_id', $user->id)
                ->where('product_id', $product->id)
                ->delete();
            $isFavorite = false;
        } else {
            \DB::table('favorites')->insert([
                'user_id' => $user->id,
                'product_id' => $product->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $isFavorite = true;
        }

        return response()->json([
            'success' => true,
            'is_favorite' => $isFavorite,
            'message' => $isFavorite ? __('products.added_to_favorites') : __('products.removed_from_favorites'),
        ]);
    }

    /**
     * List user favorites
     */
    public function favorites(Request $request)
    {
        $user = $request->user();
        $favoriteProductIds = \DB::table('favorites')
            ->where('user_id', $user->id)
            ->pluck('product_id');

        $products = Product::with(['images', 'primaryImage', 'category', 'shop', 'user'])
            ->whereIn('id', $favoriteProductIds)
            ->where('status', 'active')
            ->paginate($request->get('per_page', 20));

        $productsData = $products->getCollection()->map(function ($product) {
            return $this->formatProduct($product, [$product->id]);
        });

        return response()->json([
            'success' => true,
            'products' => $productsData,
            'pagination' => [
                'current_page' => $products->currentPage(),
                'last_page' => $products->lastPage(),
                'per_page' => $products->perPage(),
                'total' => $products->total(),
                'has_more' => $products->hasMorePages(),
            ],
        ]);
    }

    /**
     * Create a new product
     */
    public function store(Request $request)
    {
        \Log::info('========== PRODUCT STORE START ==========');
        \Log::info('[PRODUCT_STORE] Request data:', $request->except('images'));
        \Log::info('[PRODUCT_STORE] User:', [
            'user_id' => $request->user()->id,
            'name' => $request->user()->name,
            'role' => $request->user()->role,
        ]);
        \Log::info('[PRODUCT_STORE] Images count:', [
            'count' => $request->hasFile('images') ? count($request->file('images')) : 0,
        ]);

        // Renvoi d'un produit saisi hors ligne : s'il a déjà été créé (réponse
        // perdue en route), on le rend tel quel plutôt que d'en créer un second.
        $request->validate(['client_reference' => 'nullable|string|max:64']);
        $clientReference = $request->filled('client_reference')
            ? (string) $request->input('client_reference')
            : null;
        if ($clientReference !== null) {
            $existing = Product::where('user_id', $request->user()->id)
                ->where('client_reference', $clientReference)
                ->first();
            if ($existing) {
                return $this->replayedProductResponse($existing, $request);
            }
        }

        // Poids en kg : accepte la virgule décimale (« 1,5 »).
        if (is_string($request->input('weight'))) {
            $request->merge(['weight' => str_replace(',', '.', trim($request->input('weight')))]);
        }

        // Validate input
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'required|string',
            'price' => 'required|numeric|min:0',
            // Devise dans laquelle le vendeur fixe le prix (défaut XAF). Doit être active.
            'currency' => 'nullable|string|size:3|exists:currencies,code',
            'category_id' => 'bail|required|integer|exists:categories,id',
            'subcategory_id' => 'bail|nullable|integer|exists:subcategories,id',
            'type' => 'required|in:article,service',
            'origin_country' => 'nullable|string|size:2',
            'condition' => 'required|in:new,used,refurbished',
            'stock' => 'nullable|integer|min:0',
            // P4 : poids réel du colis en kg, obligatoire pour un article (calcul de la livraison).
            'weight' => 'required_if:type,article|nullable|numeric|min:0.001|max:100000',
            'weight_category' => 'nullable|in:' . implode(',', Product::WEIGHT_CATEGORIES),
            'sizes' => 'nullable|array',
            'sizes.*' => 'string|in:' . implode(',', Product::AVAILABLE_SIZES),
            'images' => 'required|array|min:1',
            'images.*' => 'file|image|mimes:jpeg,png,jpg,gif,webp|max:5120',
            // Livraison gratuite : absent/null = suit la boutique.
            'free_delivery' => 'nullable|boolean',
        ] + \App\Services\DepositOrderService::productRules('nullable') + ProductVariantService::rules()
            + \App\Support\Translation\ContentLocale::rules(Product::TRANSLATION_RULES));

        \Log::info('[PRODUCT_STORE] Validation passed');

        // 1. Verify that the user has an active package
        $vendorPackage = $request->user()->activeVendorPackage;
        if (!$vendorPackage) {
            return response()->json([
                'success' => false,
                'message' => __('products.storage_package_required'),
                'error_code' => 'NO_ACTIVE_PACKAGE',
            ], 403);
        }

        // 2. Calculate total image size
        $totalImageSizeMb = 0;
        if ($request->hasFile('images')) {
            foreach ($request->file('images') as $image) {
                $totalImageSizeMb += $image->getSize() / 1048576; // Convert bytes to MB
            }
        }

        // 3. Check if enough storage space
        if (!$vendorPackage->hasEnoughStorage($totalImageSizeMb)) {
            return response()->json([
                'success' => false,
                'message' => __('products.storage_insufficient_subscribe'),
                'error_code' => 'INSUFFICIENT_STORAGE',
                'required_mb' => round($totalImageSizeMb, 2),
                'available_mb' => round($vendorPackage->storage_remaining_mb, 2),
            ], 403);
        }

        \Log::info('[PRODUCT_STORE] Creating product...');

        // Get the user's shop (vendors should have a shop)
        $shop = $request->user()->primaryShop;
        if (!$shop) {
            \Log::warning('[PRODUCT_STORE] User has no shop');
            return response()->json([
                'success' => false,
                'message' => __('products.shop_required'),
                'error_code' => 'NO_SHOP',
            ], 403);
        }

        \Log::info('[PRODUCT_STORE] Shop found:', [
            'shop_id' => $shop->id,
            'shop_name' => $shop->name,
        ]);

        // Create the product with authenticated user
        $productData = [
            'shop_id' => $shop->id,
            'user_id' => $request->user()->id,
            'name' => $validated['name'],
            'description' => $validated['description'],
            'price' => $validated['price'],
            'currency' => strtoupper($validated['currency'] ?? 'XAF'), // price_xaf calculé auto (Product::saving)
            'category_id' => $validated['category_id'],
            'type' => $validated['type'],
            'origin_country' => isset($validated['origin_country']) ? strtoupper($validated['origin_country']) : null,
            'condition' => $validated['condition'],
            'weight_category' => $validated['weight_category'] ?? 'X-small',
            'sizes' => $this->normalizeSizes($validated['sizes'] ?? []),
            'slug' => \Str::slug($validated['name']) . '-' . \Str::random(5),
            'status' => 'active', // Set product as active (database constraint: active or inactive only)
        ];

        // Add optional fields
        if (!empty($validated['subcategory_id'])) {
            $productData['subcategory_id'] = $validated['subcategory_id'];
        }
        if (isset($validated['stock'])) {
            $productData['stock'] = $validated['stock'];
        }
        if (!empty($validated['weight'])) {
            $productData['weight'] = $validated['weight'];
        }
        if (isset($validated['free_delivery'])) {
            $productData['free_delivery'] = (bool) $validated['free_delivery'];
        }
        // Commande avec acompte (produit sur commande / importé).
        $productData += \App\Services\DepositOrderService::productAttributes($validated);

        if ($clientReference !== null) {
            $productData['client_reference'] = $clientReference;
        }

        try {
            $product = Product::create($productData);
        } catch (UniqueConstraintViolationException $e) {
            // Deux envois simultanés de la même référence : le premier a créé
            // le produit, le second le rend. Rien n'a encore été stocké ni débité.
            $existing = $clientReference !== null
                ? Product::where('user_id', $request->user()->id)
                    ->where('client_reference', $clientReference)
                    ->first()
                : null;
            if (!$existing) {
                throw $e;
            }

            return $this->replayedProductResponse($existing, $request);
        }
        if (!empty($validated['variants'])) {
            app(ProductVariantService::class)->sync($product, $validated['variants'], $validated['variant_options'] ?? null);
        }
        $product->syncTranslations($validated['translations'] ?? null);

        \Log::info('[PRODUCT_STORE] Product created:', [
            'product_id' => $product->id,
            'product_name' => $product->name,
            'user_id' => $product->user_id,
            'status' => $product->status,
        ]);

        // Store images
        if ($request->hasFile('images')) {
            $isPrimary = true;
            foreach ($request->file('images') as $image) {
                $path = $image->store('products', 'public');

                // Create product image record
                $product->images()->create([
                    'image_path' => $path,
                    'is_primary' => $isPrimary,
                ]);

                $isPrimary = false; // Only first image is primary
            }
        }

        // 4. Deduct storage space from package
        $vendorPackage->deductStorage($totalImageSizeMb);

        \Log::info('[PRODUCT_STORE] Storage deducted:', [
            'deducted_mb' => round($totalImageSizeMb, 2),
            'remaining_mb' => round($vendorPackage->storage_remaining_mb, 2),
        ]);

        // 5. Create inventory entry if stock is provided
        if (isset($validated['stock']) && $validated['stock'] > 0) {
            Inventory::create([
                'product_id' => $product->id,
                'user_id' => $request->user()->id,
                'type' => 'entry',
                'quantity' => $validated['stock'],
                'stock_after' => $validated['stock'],
                'notes' => 'Stock initial lors de la création du produit',
            ]);

            \Log::info('[PRODUCT_STORE] Inventory entry created:', [
                'product_id' => $product->id,
                'stock' => $validated['stock'],
            ]);
        }

        // Load relations for response
        $product->load(['images', 'primaryImage', 'category', 'subcategory', 'user', 'shop']);

        \Log::info('[PRODUCT_STORE] Product loaded with relations');

        // Chaque publication est annoncée à tous les utilisateurs (topic Firebase),
        // boutique certifiée ou non.
        app(ProductBroadcastService::class)->newProduct($product);

        \Log::info('========== PRODUCT STORE SUCCESS ==========');

        return response()->json([
            'success' => true,
            'message' => __('products.created'),
            'product' => $this->formatProduct($product, []) + ['translations' => $product->translationsPayload()],
            'storage_info' => [
                'used_mb' => round($totalImageSizeMb, 2),
                'remaining_mb' => round($vendorPackage->storage_remaining_mb, 2),
            ],
        ], 201);
    }

    /**
     * Réponse à un renvoi : le produit déjà créé pour cette référence, sans
     * nouvelle écriture ni nouveau débit de stockage.
     */
    private function replayedProductResponse(Product $product, Request $request)
    {
        \Log::info('[PRODUCT_STORE] Replay of client reference', [
            'product_id' => $product->id,
            'client_reference' => $product->client_reference,
        ]);

        $product->load(['images', 'primaryImage', 'category', 'subcategory', 'user', 'shop']);
        $vendorPackage = $request->user()->activeVendorPackage;

        return response()->json([
            'success' => true,
            'message' => __('products.already_created'),
            'replayed' => true,
            'product' => $this->formatProduct($product, []) + ['translations' => $product->translationsPayload()],
            'storage_info' => [
                'used_mb' => 0,
                'remaining_mb' => $vendorPackage ? round($vendorPackage->storage_remaining_mb, 2) : 0,
            ],
        ], 200);
    }

    /**
     * Format product for API response
     */
    /** Cartes produit au format des listes (réutilisé par les produits similaires d'un litige). */
    public function cards(\Illuminate\Support\Collection $products, ?\App\Models\User $user): array
    {
        $favoriteIds = $user
            ? \DB::table('favorites')->where('user_id', $user->id)->pluck('product_id')->toArray()
            : [];

        return $products->map(fn ($product) => $this->formatProduct($product, $favoriteIds))->values()->all();
    }

    private function formatProduct($product, $favoriteIds = [], $detailed = false): array
    {
        // Prix PUBLICS : prix vendeur majoré de la commission ASSO (cf. CommissionService).
        $pricing = \App\Services\CommissionService::buyerPricing($product);
        [$latitude, $longitude] = $product->publicCoordinates();

        $data = [
            'id' => $product->id,
            'name' => $product->name,
            'slug' => $product->slug,
            'description' => $product->description,
            'characteristics' => $product->characteristics,
            'commercial_information' => $product->commercial_information,
            'price' => $pricing['price'],
            'currency' => $product->currency ?? 'XAF',
            'price_xaf' => $pricing['price_xaf'],
            'min_price' => $pricing['min_price'],
            'max_price' => $pricing['max_price'],
            'price_type' => $product->price_type ?? 'fixed',
            'formatted_price' => $pricing['formatted_price'],
            'type' => $product->type ?? 'article',
            'origin_country' => $product->origin_country, // CN/TR/AE… (produits importés), null = local
            'weight_category' => $product->weight_category ?? 'X-small',
            'sizes' => $product->sizes ?? [],
            'variants' => $product->variants->where('is_active', true)->values()
                ->map(fn ($variant) => app(ProductVariantService::class)->presentVariant($variant, $product, $pricing['rate'])),
            'variant_options' => app(ProductVariantService::class)->presentOptions($product),
            'stock' => $product->stock,
            'weight' => $product->weight,
            'status' => $product->status,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'is_favorite' => in_array($product->id, $favoriteIds),
            // Asso Ads : vrai uniquement sur les cartes servies via un slot sponsorisé.
            // Positionné par injectSponsored(), jamais déduit du produit lui-même —
            // une même fiche n'est « Sponsorisé » que là où l'annonce est diffusée.
            'is_sponsored' => false,
            // Livraison gratuite offerte par le vendeur (produit, sinon boutique).
            'free_delivery' => $product->hasFreeDelivery(),
            // Commande avec acompte : % du prix payé à la commande, solde après vérification ASSO.
            ...\App\Services\DepositOrderService::productInfo($product),
            'primary_image' => $product->primaryImage ? $this->getImageUrl($product->primaryImage->image_path) : null,
            'images' => $product->images->map(fn($img) => [
                'id' => $img->id,
                'url' => $this->getImageUrl($img->image_path),
                'is_primary' => (bool) $img->is_primary,
            ]),
            'category' => $product->category ? [
                'id' => $product->category->id,
                'name' => $product->category->name,
                'slug' => $product->category->slug,
            ] : null,
            'subcategory' => $product->subcategory ? [
                'id' => $product->subcategory->id,
                'name' => $product->subcategory->name,
            ] : null,
            'seller' => $product->user ? [
                'id' => $product->user->id,
                'name' => $product->user->name,
                'avatar' => $product->user->avatar ? $this->getImageUrl($product->user->avatar) : null,
                'rating' => $product->average_rating,
                'reviews_count' => $product->reviews_count,
            ] : null,
            'shop' => $product->shop ? [
                'id' => $product->shop->id,
                'name' => $product->shop->name,
                'slug' => $product->shop->slug,
                'logo' => $product->shop->logo ? $this->getImageUrl($product->shop->logo) : null,
                'is_certified' => (bool) $product->shop->is_certified,
                'free_delivery' => (bool) $product->shop->free_delivery,
                'latitude' => $product->shop->latitude ? (float) $product->shop->latitude : null,
                'longitude' => $product->shop->longitude ? (float) $product->shop->longitude : null,
                'address' => $product->shop->address,
                'city' => $product->shop->city,
                'country' => $product->shop->country,
                'location_label' => $product->shop->location_label,
            ] : null,
            'location' => $product->shop
                ? ($product->shop->location_label ?? $product->shop->address)
                : ($product->user ? $product->user->address : null),
            'created_at' => $product->created_at->toIso8601String(),
        ];

        // Le vendeur (et lui seul) voit en plus son propre prix et ce qu'il touchera.
        if (auth('sanctum')->id() && auth('sanctum')->id() === $product->user_id) {
            $data['seller_price'] = (float) $product->price;
            $data['asso_commission_rate'] = $pricing['rate'];
            // Choix propre au produit : null = suit la boutique.
            $data['free_delivery_setting'] = $product->free_delivery;
        }

        if ($detailed) {
            $data['reviews'] = $product->reviews->map(fn($review) => [
                'id' => $review->id,
                'user' => [
                    'id' => $review->user->id,
                    'name' => $review->user->name,
                    'avatar' => $review->user->avatar ? $this->getImageUrl($review->user->avatar) : null,
                ],
                'rating' => $review->rating,
                'comment' => $review->comment,
                'is_verified' => (bool) $review->is_verified_purchase,
                'created_at' => $review->created_at->toIso8601String(),
            ]);
            $data['average_rating'] = $product->average_rating;
            $data['reviews_count'] = $product->reviews_count;
        }

        return $data;
    }

    private function normalizeSizes(array $sizes): array
    {
        $selected = array_map('strval', $sizes);

        return array_values(array_filter(
            Product::AVAILABLE_SIZES,
            fn(string $size): bool => in_array($size, $selected, true),
        ));
    }

    /**
     * Helper to get correct image URL
     * Handles both 'products/image.jpg' and 'storage/products/image.jpg' formats
     */
    private function getImageUrl($imagePath)
    {
        return media_url($imagePath);
    }

    /**
     * Remove accents from a string for accent-insensitive search
     */
    private function removeAccents($string)
    {
        $accents = [
            'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a',
            'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
            'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
            'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
            'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u',
            'ý' => 'y', 'ÿ' => 'y',
            'ñ' => 'n', 'ç' => 'c',
            'À' => 'A', 'Á' => 'A', 'Â' => 'A', 'Ã' => 'A', 'Ä' => 'A', 'Å' => 'A',
            'È' => 'E', 'É' => 'E', 'Ê' => 'E', 'Ë' => 'E',
            'Ì' => 'I', 'Í' => 'I', 'Î' => 'I', 'Ï' => 'I',
            'Ò' => 'O', 'Ó' => 'O', 'Ô' => 'O', 'Õ' => 'O', 'Ö' => 'O',
            'Ù' => 'U', 'Ú' => 'U', 'Û' => 'U', 'Ü' => 'U',
            'Ý' => 'Y',
            'Ñ' => 'N', 'Ç' => 'C',
        ];

        return strtr($string, $accents);
    }
}
