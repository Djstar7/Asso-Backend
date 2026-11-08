<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Currency;
use App\Models\ImportCountry;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVideo;
use App\Models\Shop;
use App\Models\Subcategory;
use App\Services\ProductBookService;
use App\Services\ProductBroadcastService;
use App\Services\ProductVariantService;
use App\Services\ProductVideoUploadService;
use App\Support\ImportHub;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProductController extends Controller
{
    private array $writtenImages = [];

    public function __construct(private readonly ProductVariantService $variantService) {}

    /**
     * Display a listing of products
     */
    public function index(Request $request)
    {
        $query = Product::with(['shop', 'category', 'subcategory', 'primaryImage'])
            ->withCount('reviews')
            ->latest();

        if ($request->filled('shop_id')) {
            $query->where('shop_id', $request->shop_id);
        }

        // Menu « Boutiques ASSO » : produits des seules boutiques ASSO.
        if ($request->boolean('asso')) {
            $query->whereIn('shop_id', Shop::where('user_id', \App\Support\AssoScope::ownerId())->select('id'));
        }

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->category_id);
        } else {
            // Les livres se gèrent dans la section Bibliothèque.
            $query->notLibrary();
        }

        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        if ($request->filled('price_type')) {
            $query->where('price_type', $request->price_type);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // Origine : produit importé, produit local ou pays d'un grossiste (module GROS).
        if ($request->filled('origin_country')) {
            $origin = Product::originAttributes($request->origin_country);
            $origin['origin_country']
                ? $query->where('origin_country', $origin['origin_country'])
                : $query->where(fn ($q) => $q->whereNull('origin_country')->orWhere('origin_country', ''))
                    ->where('is_local_made', $origin['is_local_made']);
        }

        // Filter wholesale only
        if ($request->boolean('wholesale_only')) {
            $query->where('is_wholesale', true);
        }

        if ($request->filled('search')) {
            $query->where('name', 'like', '%'.$request->search.'%');
        }

        $products = $query->paginate(20)->withQueryString();
        $shops = Shop::when($request->boolean('asso'), fn ($q) => $q->where('user_id', \App\Support\AssoScope::ownerId()))
            ->orderBy('name')->get();
        $categories = Category::where('is_library', false)->orderBy('name')->get();

        return view('admin.products.index', compact('products', 'shops', 'categories'));
    }

    /**
     * Show the form for creating a new product
     */
    public function create(Request $request)
    {
        // Mode Bibliothèque (?library=1) : seuls les rayons sont proposés.
        $libraryMode = $request->boolean('library');
        $this->guardLibrary($request, $libraryMode);
        $shops = Shop::where('status', 'active')->orderBy('name')->get();
        $categories = Category::where('is_library', $libraryMode)->orderBy('name')->get();
        $subcategories = Subcategory::with('category')->orderBy('name')->get();
        $importCountries = ImportCountry::activeOrdered()->get();
        $currencies = Currency::active()->orderBy('code')->get();

        return view('admin.products.create', compact('shops', 'categories', 'subcategories', 'importCountries', 'currencies', 'libraryMode'));
    }

    /**
     * Store a newly created product
     */
    public function store(Request $request)
    {
        $this->guardLibrary($request, ProductBookService::isLibraryCategory($request->integer('category_id') ?: null));

        $validated = $request->validate([
            'shop_id' => 'required|exists:shops,id',
            'category_id' => 'bail|required|integer|exists:categories,id',
            'subcategory_id' => 'bail|nullable|integer|exists:subcategories,id',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'characteristics' => 'nullable|string|max:10000',
            'commercial_information' => 'nullable|string|max:10000',
            // Versions dans les autres langues (facultatives).
            'translations' => 'sometimes|array',
            'translations.*.name' => 'nullable|string|max:255',
            'translations.*.description' => 'nullable|string|max:10000',
            'translations.*.characteristics' => 'nullable|string|max:10000',
            'translations.*.commercial_information' => 'nullable|string|max:10000',
            'currency' => 'nullable|string|size:3|exists:currencies,code',
            'price_type' => 'required|in:fixed,variable',
            'price' => 'required_if:price_type,fixed|nullable|numeric|min:0',
            'min_price' => 'required_if:price_type,variable|nullable|numeric|min:0',
            'max_price' => 'required_if:price_type,variable|nullable|numeric|min:0',
            'type' => 'required|in:service,article',
            // 'imported' / 'local' ou code d'un pays grossiste (cf. Product::originAttributes).
            'origin_country' => ['nullable', 'string', function ($attribute, $value, $fail) {
                if (! in_array($value, [Product::ORIGIN_IMPORTED, Product::ORIGIN_LOCAL], true)
                    && ! ImportCountry::where('code', strtoupper($value))->exists()) {
                    $fail('Origine du produit invalide.');
                }
            }],
            'weight' => [
                // P4 : le poids compte partout (livraison locale, interurbaine, import).
                Rule::requiredIf(fn () => $request->input('type') === 'article'),
                'nullable', 'numeric', 'min:0.001', 'max:999999',
            ],
            'weight_category' => 'sometimes|in:'.implode(',', Product::WEIGHT_CATEGORIES),
            'sizes' => 'nullable|array',
            'sizes.*' => 'string|in:'.implode(',', Product::AVAILABLE_SIZES),
            'stock' => 'required|integer|min:0',
            'status' => 'required|in:active,inactive',
            'requires_quote' => 'sometimes|boolean',
            'images' => 'nullable|array|max:15',
            'images.*' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:5120',

            'is_wholesale' => 'nullable|boolean',
            // Livraison gratuite : 1 = offerte, 0 = non.
            'free_delivery' => 'nullable|boolean',
            // Commande avec acompte (% du prix client).
            'deposit_enabled' => 'nullable|boolean',
            'deposit_rate' => 'required_if_accepted:deposit_enabled|nullable|numeric|min:1|max:99',
            'tiers' => 'nullable|array',
            'tiers.*.label' => 'required_with:tiers|string|max:255',
            'tiers.*.unit_price' => 'required_with:tiers|numeric|min:0',
            'tiers.*.min_quantity' => 'required_with:tiers|integer|min:1',
            'tiers.*.pack_size' => 'nullable|integer|min:1',
            'tiers.*.weight_kg' => 'nullable|numeric|min:0',
            'tiers.*.length_cm' => 'nullable|numeric|min:1|max:3000',
            'tiers.*.width_cm' => 'nullable|numeric|min:1|max:3000',
            'tiers.*.height_cm' => 'nullable|numeric|min:1|max:3000',
            'length_cm' => 'nullable|numeric|min:1|max:3000',
            'width_cm' => 'nullable|numeric|min:1|max:3000',
            'height_cm' => 'nullable|numeric|min:1|max:3000',
            // Les options (couleurs, tailles) se cumulent pour atteindre un palier.
            'tier_mix_variants' => 'nullable|boolean',

            // Vidéo déjà envoyée par morceaux (ProductVideoController) : on ne reçoit que son id.
            'video_id' => 'nullable|integer|exists:product_videos,id',
            'remove_video' => 'nullable|boolean',
        ] + ProductVariantService::rules() + \App\Support\DeliveryDelay::rules()
            + ProductBookService::rules($request->integer('category_id') ?: null));

        $this->assertDistinctTierThresholds($validated['tiers'] ?? []);
        $this->assertCoherentDimensions($validated, $validated['tiers'] ?? []);

        // Isole les données "gros" AVANT toute insertion — elles ne vont pas dans `products`
        $tiers = $validated['tiers'] ?? [];
        $variants = $validated['variants'] ?? [];
        $variantOptions = $validated['variant_options'] ?? null;
        $isWholesale = $request->boolean('is_wholesale');
        $book = $validated['book'] ?? null;
        unset($validated['tiers'], $validated['variants'], $validated['variant_options'], $validated['is_wholesale'], $validated['images'], $validated['video_id'], $validated['remove_video'], $validated['book']);
        $validated['currency'] = strtoupper($validated['currency'] ?? 'XAF');

        // Produit en gros : toujours la boutique « ASSO Import Douala » (entrepôt de réception).
        if ($isWholesale && ($hub = ImportHub::shop())) {
            $validated['shop_id'] = $hub->id;
        }

        // Get shop owner
        $shop = Shop::findOrFail($validated['shop_id']);
        $validated['user_id'] = $shop->user_id;
        $validated['weight_category'] = $validated['weight_category'] ?? 'X-small';
        $validated['sizes'] = $this->normalizeSizes($request->input('sizes', []));

        // Origine : vide / « imported » = produit importé, « local » = fabriqué au Cameroun,
        // sinon pays du grossiste (le produit passe alors dans l'onglet Import).
        $validated = Product::originAttributes($request->input('origin_country')) + $validated;
        // P4 : le poids compte pour tout article, local ou importé ; seul un service n'en a pas.
        if ($validated['type'] === 'service') {
            $validated['weight'] = null;
        }

        // Generate slug
        $validated['slug'] = Product::uniqueSlug($validated['name']);

        // Vente en gros
        $validated['is_wholesale'] = $isWholesale;
        $validated['tier_mix_variants'] = $request->boolean('tier_mix_variants', true);
        // Livraison gratuite : le produit ne garde un réglage propre que s'il
        // diffère de celui de sa boutique ; sinon il la suit.
        $freeDelivery = $request->boolean('free_delivery');
        $validated['free_delivery'] = $freeDelivery === (bool) $shop->free_delivery ? null : $freeDelivery;
        $validated['deposit_enabled'] = $request->boolean('deposit_enabled');

        $this->writtenImages = [];
        try {
            $product = DB::transaction(function () use ($validated, $tiers, $variants, $variantOptions, $book, $request) {
                // Create product
                $product = Product::create($validated);
                $product->syncTranslations($validated['translations'] ?? null);
                app(ProductBookService::class)->sync($product, $book);

                // Paliers de prix (module GROS)
                $this->syncPriceTiers($product, $tiers);
                $this->variantService->sync($product, $variants, $variantOptions);

                // Handle images upload
                if ($request->hasFile('images')) {
                    $this->uploadImages($product, $request->file('images'));
                }

                $this->syncVideo($product, $request);

                return $product;
            });
        } catch (\Throwable $exception) {
            foreach ($this->writtenImages as $path) {
                File::delete($path);
            }
            throw $exception;
        }

        // Publié directement par l'admin : annoncé comme une publication vendeur.
        if ($product->status === 'active') {
            app(ProductBroadcastService::class)->newProduct($product);
        }

        return $product->book()->exists()
            ? redirect()->route('admin.library.books.index')->with('success', 'Livre ajouté à la Bibliothèque.')
            : redirect()->route('admin.products.index')->with('success', 'Produit créé avec succès!');
    }

    /**
     * Display the specified product
     */
    public function show(Request $request, Product $product)
    {
        $this->guardLibrary($request, $product->isBook());
        $product->load(['shop', 'category', 'subcategory', 'images', 'user', 'priceTiers', 'variants', 'book']);

        return view('admin.products.show', compact('product'));
    }

    /**
     * Show the form for editing the specified product
     */
    public function edit(Request $request, Product $product)
    {
        $product->load(['images', 'priceTiers', 'variants', 'book', 'category']);
        $libraryMode = $product->isBook();
        $this->guardLibrary($request, $libraryMode);
        $shops = Shop::where('status', 'active')->orderBy('name')->get();
        $categories = Category::where('is_library', $libraryMode)->orderBy('name')->get();
        $subcategories = Subcategory::with('category')->orderBy('name')->get();
        $importCountries = ImportCountry::activeOrdered()->get();
        $currencies = Currency::active()->orderBy('code')->get();

        return view('admin.products.edit', compact('product', 'shops', 'categories', 'subcategories', 'importCountries', 'currencies', 'libraryMode'));
    }

    /**
     * Update the specified product
     */
    public function update(Request $request, Product $product)
    {
        $this->guardLibrary($request, $product->isBook()
            || ProductBookService::isLibraryCategory($request->integer('category_id') ?: null));

        $validated = $request->validate([
            'shop_id' => 'required|exists:shops,id',
            'category_id' => 'bail|required|integer|exists:categories,id',
            'subcategory_id' => 'bail|nullable|integer|exists:subcategories,id',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'characteristics' => 'nullable|string|max:10000',
            'commercial_information' => 'nullable|string|max:10000',
            // Versions dans les autres langues (facultatives).
            'translations' => 'sometimes|array',
            'translations.*.name' => 'nullable|string|max:255',
            'translations.*.description' => 'nullable|string|max:10000',
            'translations.*.characteristics' => 'nullable|string|max:10000',
            'translations.*.commercial_information' => 'nullable|string|max:10000',
            'currency' => 'nullable|string|size:3|exists:currencies,code',
            'price_type' => 'required|in:fixed,variable',
            'price' => 'required_if:price_type,fixed|nullable|numeric|min:0',
            'min_price' => 'required_if:price_type,variable|nullable|numeric|min:0',
            'max_price' => 'required_if:price_type,variable|nullable|numeric|min:0',
            'type' => 'required|in:service,article',
            // 'imported' / 'local' ou code d'un pays grossiste (cf. Product::originAttributes).
            'origin_country' => ['nullable', 'string', function ($attribute, $value, $fail) {
                if (! in_array($value, [Product::ORIGIN_IMPORTED, Product::ORIGIN_LOCAL], true)
                    && ! ImportCountry::where('code', strtoupper($value))->exists()) {
                    $fail('Origine du produit invalide.');
                }
            }],
            'weight' => [
                // P4 : le poids compte partout (livraison locale, interurbaine, import).
                Rule::requiredIf(fn () => $request->input('type') === 'article'),
                'nullable', 'numeric', 'min:0.001', 'max:999999',
            ],
            'weight_category' => 'sometimes|in:'.implode(',', Product::WEIGHT_CATEGORIES),
            'sizes' => 'nullable|array',
            'sizes.*' => 'string|in:'.implode(',', Product::AVAILABLE_SIZES),
            'stock' => 'required|integer|min:0',
            'status' => 'required|in:active,inactive',
            'requires_quote' => 'sometimes|boolean',
            'images' => 'nullable|array|max:15',
            'images.*' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:5120',

            'is_wholesale' => 'nullable|boolean',
            // Livraison gratuite : 1 = offerte, 0 = non.
            'free_delivery' => 'nullable|boolean',
            // Commande avec acompte (% du prix client).
            'deposit_enabled' => 'nullable|boolean',
            'deposit_rate' => 'required_if_accepted:deposit_enabled|nullable|numeric|min:1|max:99',
            'tiers' => 'nullable|array',
            'tiers.*.label' => 'required_with:tiers|string|max:255',
            'tiers.*.unit_price' => 'required_with:tiers|numeric|min:0',
            'tiers.*.min_quantity' => 'required_with:tiers|integer|min:1',
            'tiers.*.pack_size' => 'nullable|integer|min:1',
            'tiers.*.weight_kg' => 'nullable|numeric|min:0',
            'tiers.*.length_cm' => 'nullable|numeric|min:1|max:3000',
            'tiers.*.width_cm' => 'nullable|numeric|min:1|max:3000',
            'tiers.*.height_cm' => 'nullable|numeric|min:1|max:3000',
            'length_cm' => 'nullable|numeric|min:1|max:3000',
            'width_cm' => 'nullable|numeric|min:1|max:3000',
            'height_cm' => 'nullable|numeric|min:1|max:3000',
            // Les options (couleurs, tailles) se cumulent pour atteindre un palier.
            'tier_mix_variants' => 'nullable|boolean',

            // Vidéo déjà envoyée par morceaux (ProductVideoController) : on ne reçoit que son id.
            'video_id' => 'nullable|integer|exists:product_videos,id',
            'remove_video' => 'nullable|boolean',
        ] + ProductVariantService::rules() + \App\Support\DeliveryDelay::rules()
            + ProductBookService::rules($request->integer('category_id') ?: null));

        $this->assertDistinctTierThresholds($validated['tiers'] ?? []);
        $this->assertCoherentDimensions($validated, $validated['tiers'] ?? []);

        // Isole les données "gros" AVANT l'update — elles ne vont pas dans `products`
        $tiers = $validated['tiers'] ?? [];
        $variants = $validated['variants'] ?? [];
        $variantOptions = $validated['variant_options'] ?? null;
        $isWholesale = $request->boolean('is_wholesale');
        $book = $validated['book'] ?? null;
        unset($validated['tiers'], $validated['variants'], $validated['variant_options'], $validated['is_wholesale'], $validated['images'], $validated['video_id'], $validated['remove_video'], $validated['book']);
        $validated['currency'] = strtoupper($validated['currency'] ?? $product->currency ?? 'XAF');

        // Produit en gros : toujours la boutique « ASSO Import Douala » (entrepôt de réception).
        if ($isWholesale && ($hub = ImportHub::shop())) {
            $validated['shop_id'] = $hub->id;
        }

        // Get shop owner
        $shop = Shop::findOrFail($validated['shop_id']);
        $validated['user_id'] = $shop->user_id;
        $validated['sizes'] = $this->normalizeSizes($request->input('sizes', []));

        // Origine : vide / « imported » = produit importé, « local » = fabriqué au Cameroun,
        // sinon pays du grossiste (le produit passe alors dans l'onglet Import).
        $validated = Product::originAttributes($request->input('origin_country')) + $validated;
        // P4 : le poids compte pour tout article, local ou importé ; seul un service n'en a pas.
        if ($validated['type'] === 'service') {
            $validated['weight'] = null;
        }

        // Update slug if name changed
        if ($product->name !== $validated['name']) {
            $validated['slug'] = Product::uniqueSlug($validated['name'], $product->id);
        }

        // Vente en gros
        $validated['is_wholesale'] = $isWholesale;
        $validated['tier_mix_variants'] = $request->boolean('tier_mix_variants', true);
        // Livraison gratuite : le produit ne garde un réglage propre que s'il
        // diffère de celui de sa boutique ; sinon il la suit.
        $freeDelivery = $request->boolean('free_delivery');
        $validated['free_delivery'] = $freeDelivery === (bool) $shop->free_delivery ? null : $freeDelivery;
        $validated['deposit_enabled'] = $request->boolean('deposit_enabled');

        // Masquer / afficher est une permission distincte de la modification.
        if (! $request->user()->hasAdminPermission('products.toggle_visibility')) {
            $validated['status'] = $product->status;
        }

        $this->writtenImages = [];
        try {
            $product = DB::transaction(function () use ($product, $validated, $tiers, $variants, $variantOptions, $book, $request) {
                // Update product
                $product->update($validated);
                $product->syncTranslations($validated['translations'] ?? null);
                app(ProductBookService::class)->sync($product, $book);

                // Paliers de prix (module GROS) — remplace intégralement l'ancienne liste
                $this->syncPriceTiers($product, $tiers);
                $this->variantService->sync($product, $variants, $variantOptions);

                // Handle new images upload
                if ($request->hasFile('images')) {
                    $this->uploadImages($product, $request->file('images'));
                }

                $this->syncVideo($product, $request);

                return $product;
            });
        } catch (\Throwable $exception) {
            foreach ($this->writtenImages as $path) {
                File::delete($path);
            }
            throw $exception;
        }

        return $product->book()->exists()
            ? redirect()->route('admin.library.books.index')->with('success', 'Livre mis à jour.')
            : redirect()->route('admin.products.index')->with('success', 'Produit mis à jour avec succès!');
    }

    /**
     * Remove the specified product
     */
    public function destroy(Request $request, Product $product)
    {
        $this->guardLibrary($request, $product->isBook());

        foreach ($product->images as $image) {
            if (File::exists(public_path($image->image_path))) {
                File::delete(public_path($image->image_path));
            }
        }

        // La cascade SQL supprimerait les lignes sans leurs fichiers : on passe par le modèle.
        ProductVideo::where('product_id', $product->id)->get()->each->delete();

        $wasBook = $product->book()->exists();
        $product->delete();

        return $wasBook
            ? redirect()->route('admin.library.books.index')->with('success', 'Livre supprimé.')
            : redirect()->route('admin.products.index')->with('success', 'Produit supprimé avec succès!');
    }

    /**
     * Masque ou réaffiche un produit sur la plateforme (statut active/inactive).
     */
    public function toggleStatus(Request $request, Product $product)
    {
        $this->guardLibrary($request, $product->isBook());
        $product->update(['status' => $product->status === 'active' ? 'inactive' : 'active']);

        return back()->with('success', $product->status === 'active'
            ? 'Produit de nouveau visible sur la plateforme.'
            : 'Produit masqué de la plateforme.');
    }

    /** Rattache la vidéo envoyée au produit, ou retire l'ancienne. */
    private function syncVideo(Product $product, Request $request): void
    {
        app(ProductVideoUploadService::class)->syncForProduct(
            $product,
            $request->integer('video_id') ?: null,
            $request->boolean('remove_video'),
        );
    }

    /**
     * Le palier appliqué se déduit de la quantité : deux paliers au même seuil
     * rendraient le prix ambigu.
     */
    /**
     * Dimensions facultatives, mais complètes et cohérentes avec le poids : au-delà de
     * 20 000 kg/m³ (plus dense que le plomb), l'unité saisie n'est pas le centimètre.
     */
    private function assertCoherentDimensions(array $product, array $tiers): void
    {
        $sources = ['' => [$product, null]];
        foreach ($tiers as $i => $tier) {
            $sources["tiers.$i."] = [$tier, $tier['weight_kg'] ?? null];
        }
        foreach ($sources as $prefix => [$values, $ownWeight]) {
            $dimensions = array_filter([$values['length_cm'] ?? null, $values['width_cm'] ?? null, $values['height_cm'] ?? null], 'filled');
            if ($dimensions === []) {
                continue;
            }
            if (count($dimensions) < 3) {
                throw ValidationException::withMessages([$prefix.'length_cm' => 'Renseignez la longueur, la largeur et la hauteur, ou laissez les trois vides.']);
            }
            $weight = (float) (filled($ownWeight) ? $ownWeight : ($product['weight'] ?? 0));
            if ($weight / (array_product(array_map('floatval', $dimensions)) / 1_000_000) > 20000) {
                throw ValidationException::withMessages([$prefix.'length_cm' => 'Dimensions incohérentes avec le poids : saisissez bien des centimètres, pas des mètres.']);
            }
        }
    }

    private function assertDistinctTierThresholds(array $tiers): void
    {
        $thresholds = collect($tiers)
            ->filter(fn ($tier) => ! empty($tier['label']) && isset($tier['unit_price']))
            ->map(fn ($tier) => (int) ($tier['min_quantity'] ?? 1));

        if ($thresholds->count() !== $thresholds->unique()->count()) {
            throw ValidationException::withMessages([
                'tiers' => 'Deux paliers ne peuvent pas partir de la même quantité : le prix appliqué serait ambigu.',
            ]);
        }
    }

    /**
     * Synchronise les paliers de prix (vente en gros) d'un produit.
     * Stratégie "replace all" : simple, robuste, aucun palier orphelin possible.
     */
    private function syncPriceTiers(Product $product, array $tiers): void
    {
        $product->priceTiers()->delete();

        foreach ($tiers as $i => $tier) {
            if (empty($tier['label']) || ! isset($tier['unit_price'])) {
                continue; // ignore les lignes vides envoyées par erreur
            }

            $product->priceTiers()->create([
                'label' => $tier['label'],
                'unit_price' => $tier['unit_price'],
                'min_quantity' => $tier['min_quantity'] ?? 1,
                'pack_size' => $tier['pack_size'] ?? 1,
                // Poids d'une unité de ce palier (pack, bidon, pièce) : sert au prix au kg et à SOLEX.
                'weight_kg' => isset($tier['weight_kg']) && $tier['weight_kg'] !== '' ? (float) $tier['weight_kg'] : null,
                'length_cm' => filled($tier['length_cm'] ?? null) ? (float) $tier['length_cm'] : null,
                'width_cm' => filled($tier['width_cm'] ?? null) ? (float) $tier['width_cm'] : null,
                'height_cm' => filled($tier['height_cm'] ?? null) ? (float) $tier['height_cm'] : null,
                'currency' => $product->currency ?? 'XAF',
                'is_active' => true,
                'sort_order' => $i + 1,
            ]);
        }
    }

    private function normalizeSizes(array $sizes): array
    {
        $selected = array_map('strval', $sizes);

        return array_values(array_filter(
            Product::AVAILABLE_SIZES,
            fn (string $size): bool => in_array($size, $selected, true),
        ));
    }

    /**
     * Upload product images
     */
    private function uploadImages(Product $product, array $images)
    {
        if ($product->images()->count() + count($images) > 15) {
            throw ValidationException::withMessages(['images' => 'Un produit peut contenir au maximum 15 photos.']);
        }
        $order = $product->images()->max('order') ?? 0;
        $isPrimary = $product->images()->count() === 0;

        foreach ($images as $image) {
            $order++;
            $imageName = Str::uuid().'.'.$image->getClientOriginalExtension();
            $image->move(public_path('storage/products'), $imageName);
            $this->writtenImages[] = public_path('storage/products/'.$imageName);

            ProductImage::create([
                'product_id' => $product->id,
                'image_path' => 'storage/products/'.$imageName,
                'is_primary' => $isPrimary,
                'order' => $order,
            ]);

            $isPrimary = false;
        }
    }

    /**
     * Delete a specific product image
     */
    public function deleteImage(Request $request, Product $product, ProductImage $image)
    {
        if ($image->product_id !== $product->id) {
            return response()->json(['error' => 'Image not found'], 404);
        }

        if (File::exists(public_path($image->image_path))) {
            File::delete(public_path($image->image_path));
        }

        $wasPrimary = $image->is_primary;
        $image->delete();

        if ($wasPrimary) {
            $firstImage = $product->images()->orderBy('order')->first();
            if ($firstImage) {
                $firstImage->update(['is_primary' => true]);
            }
        }

        return response()->json(['success' => true]);
    }

    /**
     * Set an image as primary
     */
    public function setPrimaryImage(Request $request, Product $product, ProductImage $image)
    {
        if ($image->product_id !== $product->id) {
            return response()->json(['error' => 'Image not found'], 404);
        }

        $product->images()->update(['is_primary' => false]);
        $image->update(['is_primary' => true]);

        return response()->json(['success' => true]);
    }

    /**
     * Réordonne les photos d'un produit ; la première devient la photo principale.
     */
    public function reorderImages(Request $request, Product $product)
    {
        $ids = $request->validate([
            'order' => 'required|array|min:1',
            'order.*' => 'integer',
        ])['order'];

        $images = $product->images()->whereIn('id', $ids)->get()->keyBy('id');
        foreach (array_values($ids) as $position => $id) {
            $images->get($id)?->update([
                'order' => $position + 1,
                'is_primary' => $position === 0,
            ]);
        }
        if ($images->has($ids[0] ?? null)) {
            $product->images()->where('id', '!=', $ids[0])->update(['is_primary' => false]);
        }

        return response()->json(['success' => true]);
    }

    /**
     * Get subcategories for a category (AJAX)
     */
    /** La Bibliothèque est réservée à l'admin, y compris par le formulaire produit. */
    private function guardLibrary(Request $request, bool $isBook): void
    {
        abort_if($isBook && ! $request->user()->canAccessAdminRoute('admin.library.books.index'), 403);
    }

    public function getSubcategories(Category $category)
    {
        return response()->json($category->subcategories);
    }
}
