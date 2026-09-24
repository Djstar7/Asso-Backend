<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVideo;
use App\Models\Category;
use App\Models\Subcategory;
use App\Models\Shop;
use App\Models\ImportCountry;
use App\Models\Currency;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\Rule;
use App\Models\DeliveryPricelist;
use App\Services\ProductBroadcastService;
use App\Services\ProductVariantService;
use App\Support\ImportHub;

class ProductController extends Controller
{
    public function __construct(private readonly ProductVariantService $variantService)
    {
    }

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

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->category_id);
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

        // Filter by origin country (module GROS)
        if ($request->filled('origin_country')) {
            $query->where('origin_country', strtoupper($request->origin_country));
        }

        // Filter wholesale only
        if ($request->boolean('wholesale_only')) {
            $query->where('is_wholesale', true);
        }

        if ($request->filled('search')) {
            $query->where('name', 'like', '%' . $request->search . '%');
        }

        $products = $query->paginate(20);
        $shops = Shop::orderBy('name')->get();
        $categories = Category::orderBy('name')->get();

        return view('admin.products.index', compact('products', 'shops', 'categories'));
    }

    /**
     * Show the form for creating a new product
     */
    public function create()
    {
        $shops = Shop::where('status', 'active')->orderBy('name')->get();
        $categories = Category::orderBy('name')->get();
        $subcategories = Subcategory::with('category')->orderBy('name')->get();
        $importCountries = ImportCountry::activeOrdered()->get();
        $currencies = Currency::active()->orderBy('code')->get();

        return view('admin.products.create', compact('shops', 'categories', 'subcategories', 'importCountries', 'currencies'));
    }

    /**
     * Store a newly created product
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'shop_id' => 'required|exists:shops,id',
            'category_id' => 'required|exists:categories,id',
            'subcategory_id' => 'nullable|exists:subcategories,id',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'characteristics' => 'nullable|string|max:10000',
            'commercial_information' => 'nullable|string|max:10000',
            'currency' => 'nullable|string|size:3|exists:currencies,code',
            'price_type' => 'required|in:fixed,variable',
            'price' => 'required_if:price_type,fixed|nullable|numeric|min:0',
            'min_price' => 'required_if:price_type,variable|nullable|numeric|min:0',
            'max_price' => 'required_if:price_type,variable|nullable|numeric|min:0',
            'type' => 'required|in:service,article',
            'origin_country' => 'nullable|exists:import_countries,code',
            'weight' => [
                // P4 : le poids compte partout (livraison locale, interurbaine, import).
                Rule::requiredIf(fn () => $request->input('type') === 'article'),
                'nullable', 'numeric', 'min:0.001', 'max:999999',
            ],
            'weight_category' => 'sometimes|in:' . implode(',', Product::WEIGHT_CATEGORIES),
            'sizes' => 'nullable|array',
            'sizes.*' => 'string|in:' . implode(',', Product::AVAILABLE_SIZES),
            'stock' => 'required|integer|min:0',
            'status' => 'required|in:active,inactive',
            'images' => 'nullable|array|max:15',
            'images.*' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:5120',

            'is_wholesale'         => 'nullable|boolean',
            'tiers'                => 'nullable|array',
            'tiers.*.label'        => 'required_with:tiers|string|max:255',
            'tiers.*.unit_price'   => 'required_with:tiers|numeric|min:0',
            'tiers.*.min_quantity' => 'required_with:tiers|integer|min:1',
            'tiers.*.pack_size'    => 'nullable|integer|min:1',
            'tiers.*.weight_kg'    => 'nullable|numeric|min:0',

            // Vidéo déjà envoyée par morceaux (ProductVideoController) : on ne reçoit que son id.
            'video_id'             => 'nullable|integer|exists:product_videos,id',
            'remove_video'         => 'nullable|boolean',
        ] + ProductVariantService::rules());

        // Isole les données "gros" AVANT toute insertion — elles ne vont pas dans `products`
        $tiers = $validated['tiers'] ?? [];
        $variants = $validated['variants'] ?? [];
        $variantOptions = $validated['variant_options'] ?? null;
        $isWholesale = $request->boolean('is_wholesale');
        unset($validated['tiers'], $validated['variants'], $validated['variant_options'], $validated['is_wholesale'], $validated['images'], $validated['video_id'], $validated['remove_video']);
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

        // Pays d'origine (produits importés). Vide = produit local.
        $validated['origin_country'] = $request->filled('origin_country')
            ? strtoupper($request->input('origin_country'))
            : null;
        // P4 : le poids compte pour tout article, local ou importé ; seul un service n'en a pas.
        if ($validated['type'] === 'service') {
            $validated['weight'] = null;
        }

        // Generate slug
        $validated['slug'] = Str::slug($validated['name']);

        // Vente en gros
        $validated['is_wholesale'] = $isWholesale;

        // Create product
        $product = Product::create($validated);

        // Paliers de prix (module GROS)
        $this->syncPriceTiers($product, $tiers);
        $this->variantService->sync($product, $variants, $variantOptions);

        // Handle images upload
        if ($request->hasFile('images')) {
            $this->uploadImages($product, $request->file('images'));
        }

        $this->syncVideo($product, $request);

        // Publié directement par l'admin : annoncé comme une publication vendeur.
        if ($product->status === 'active') {
            app(ProductBroadcastService::class)->newProduct($product);
        }

        return redirect()->route('admin.products.index')->with('success', 'Produit créé avec succès!');
    }

    /**
     * Display the specified product
     */
    public function show(Product $product)
    {
        $product->load(['shop', 'category', 'subcategory', 'images', 'user', 'priceTiers', 'variants']);

        return view('admin.products.show', compact('product'));
    }

    /**
     * Show the form for editing the specified product
     */
    public function edit(Product $product)
    {
        $product->load(['images', 'priceTiers', 'variants']);
        $shops = Shop::where('status', 'active')->orderBy('name')->get();
        $categories = Category::orderBy('name')->get();
        $subcategories = Subcategory::with('category')->orderBy('name')->get();
        $importCountries = ImportCountry::activeOrdered()->get();
        $currencies = Currency::active()->orderBy('code')->get();

        return view('admin.products.edit', compact('product', 'shops', 'categories', 'subcategories', 'importCountries', 'currencies'));
    }

    /**
     * Update the specified product
     */
    public function update(Request $request, Product $product)
    {
        $validated = $request->validate([
            'shop_id' => 'required|exists:shops,id',
            'category_id' => 'required|exists:categories,id',
            'subcategory_id' => 'nullable|exists:subcategories,id',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'characteristics' => 'nullable|string|max:10000',
            'commercial_information' => 'nullable|string|max:10000',
            'currency' => 'nullable|string|size:3|exists:currencies,code',
            'price_type' => 'required|in:fixed,variable',
            'price' => 'required_if:price_type,fixed|nullable|numeric|min:0',
            'min_price' => 'required_if:price_type,variable|nullable|numeric|min:0',
            'max_price' => 'required_if:price_type,variable|nullable|numeric|min:0',
            'type' => 'required|in:service,article',
            'origin_country' => 'nullable|exists:import_countries,code',
            'weight' => [
                // P4 : le poids compte partout (livraison locale, interurbaine, import).
                Rule::requiredIf(fn () => $request->input('type') === 'article'),
                'nullable', 'numeric', 'min:0.001', 'max:999999',
            ],
            'weight_category' => 'sometimes|in:' . implode(',', Product::WEIGHT_CATEGORIES),
            'sizes' => 'nullable|array',
            'sizes.*' => 'string|in:' . implode(',', Product::AVAILABLE_SIZES),
            'stock' => 'required|integer|min:0',
            'status' => 'required|in:active,inactive',
            'images' => 'nullable|array|max:15',
            'images.*' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:5120',

            'is_wholesale'         => 'nullable|boolean',
            'tiers'                => 'nullable|array',
            'tiers.*.label'        => 'required_with:tiers|string|max:255',
            'tiers.*.unit_price'   => 'required_with:tiers|numeric|min:0',
            'tiers.*.min_quantity' => 'required_with:tiers|integer|min:1',
            'tiers.*.pack_size'    => 'nullable|integer|min:1',
            'tiers.*.weight_kg'    => 'nullable|numeric|min:0',

            // Vidéo déjà envoyée par morceaux (ProductVideoController) : on ne reçoit que son id.
            'video_id'             => 'nullable|integer|exists:product_videos,id',
            'remove_video'         => 'nullable|boolean',
        ] + ProductVariantService::rules());

        // Isole les données "gros" AVANT l'update — elles ne vont pas dans `products`
        $tiers = $validated['tiers'] ?? [];
        $variants = $validated['variants'] ?? [];
        $variantOptions = $validated['variant_options'] ?? null;
        $isWholesale = $request->boolean('is_wholesale');
        unset($validated['tiers'], $validated['variants'], $validated['variant_options'], $validated['is_wholesale'], $validated['images'], $validated['video_id'], $validated['remove_video']);
        $validated['currency'] = strtoupper($validated['currency'] ?? $product->currency ?? 'XAF');

        // Produit en gros : toujours la boutique « ASSO Import Douala » (entrepôt de réception).
        if ($isWholesale && ($hub = ImportHub::shop())) {
            $validated['shop_id'] = $hub->id;
        }

        // Get shop owner
        $shop = Shop::findOrFail($validated['shop_id']);
        $validated['user_id'] = $shop->user_id;
        $validated['sizes'] = $this->normalizeSizes($request->input('sizes', []));

        // Pays d'origine (produits importés). Vide = produit local (repasse à null).
        $validated['origin_country'] = $request->filled('origin_country')
            ? strtoupper($request->input('origin_country'))
            : null;
        // P4 : le poids compte pour tout article, local ou importé ; seul un service n'en a pas.
        if ($validated['type'] === 'service') {
            $validated['weight'] = null;
        }

        // Update slug if name changed
        if ($product->name !== $validated['name']) {
            $validated['slug'] = Str::slug($validated['name']);
        }

        // Vente en gros
        $validated['is_wholesale'] = $isWholesale;

        // Update product
        $product->update($validated);

        // Paliers de prix (module GROS) — remplace intégralement l'ancienne liste
        $this->syncPriceTiers($product, $tiers);
        $this->variantService->sync($product, $variants, $variantOptions);

        // Handle new images upload
        if ($request->hasFile('images')) {
            $this->uploadImages($product, $request->file('images'));
        }

        $this->syncVideo($product, $request);

        return redirect()->route('admin.products.index')->with('success', 'Produit mis à jour avec succès!');
    }

    /**
     * Remove the specified product
     */
    public function destroy(Product $product)
    {
        foreach ($product->images as $image) {
            if (File::exists(public_path($image->image_path))) {
                File::delete(public_path($image->image_path));
            }
        }

        // La cascade SQL supprimerait les lignes sans leurs fichiers : on passe par le modèle.
        ProductVideo::where('product_id', $product->id)->get()->each->delete();

        $product->delete();

        return redirect()->route('admin.products.index')->with('success', 'Produit supprimé avec succès!');
    }

    /**
     * Rattache la vidéo envoyée au produit, ou retire l'ancienne.
     *
     * Réservé pour l'instant aux produits grossistes (pays d'import + vente en
     * gros) : sur tout autre produit, une vidéo envoyée est supprimée plutôt
     * que laissée orpheline.
     */
    private function syncVideo(Product $product, Request $request): void
    {
        $isImport = $product->is_wholesale && !empty($product->origin_country);
        $newId = $request->integer('video_id') ?: null;

        $current = ProductVideo::where('product_id', $product->id)->get();
        $replaceOrRemove = !$isImport
            || $request->boolean('remove_video')
            || ($newId && !$current->contains('id', $newId));

        if ($replaceOrRemove) {
            $current->reject(fn (ProductVideo $v) => $v->id === $newId)->each->delete();
        }

        if (!$newId) {
            return;
        }

        // Seule une vidéo encore libre (ou déjà la sienne) peut être rattachée.
        $video = ProductVideo::whereKey($newId)
            ->where(fn ($q) => $q->whereNull('product_id')->orWhere('product_id', $product->id))
            ->first();

        if (!$video) {
            return;
        }

        // `remove_video` vise l'ancienne vidéo : une nouvelle envoyée en même temps la remplace.
        if ($isImport) {
            $video->update(['product_id' => $product->id]);
        } elseif ($video->product_id === null) {
            $video->delete();
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
            if (empty($tier['label']) || !isset($tier['unit_price'])) {
                continue; // ignore les lignes vides envoyées par erreur
            }

            $product->priceTiers()->create([
                'label'        => $tier['label'],
                'unit_price'   => $tier['unit_price'],
                'min_quantity' => $tier['min_quantity'] ?? 1,
                'pack_size'    => $tier['pack_size'] ?? 1,
                // Poids d'une unité de ce palier (pack, bidon, pièce) : sert au prix au kg et à SOLEX.
                'weight_kg'    => isset($tier['weight_kg']) && $tier['weight_kg'] !== '' ? (float) $tier['weight_kg'] : null,
                'currency'     => $product->currency ?? 'XAF',
                'is_active'    => true,
                'sort_order'   => $i + 1,
            ]);
        }
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
     * Upload product images
     */
    private function uploadImages(Product $product, array $images)
    {
        $order = $product->images()->max('order') ?? 0;
        $isPrimary = $product->images()->count() === 0;

        foreach ($images as $image) {
            $order++;
            $imageName = time() . '_' . $order . '_' . Str::slug($product->name) . '.' . $image->getClientOriginalExtension();
            $image->move(public_path('storage/products'), $imageName);

            ProductImage::create([
                'product_id' => $product->id,
                'image_path' => 'storage/products/' . $imageName,
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
    public function getSubcategories(Category $category)
    {
        return response()->json($category->subcategories);
    }
}
