<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Category;
use App\Models\Subcategory;
use App\Models\Shop;
use App\Models\ImportCountry;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\File;
use App\Models\DeliveryPricelist;

class ProductController extends Controller
{
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

        return view('admin.products.create', compact('shops', 'categories', 'subcategories', 'importCountries'));
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
            'price_type' => 'required|in:fixed,variable',
            'price' => 'required_if:price_type,fixed|nullable|numeric|min:0',
            'min_price' => 'required_if:price_type,variable|nullable|numeric|min:0',
            'max_price' => 'required_if:price_type,variable|nullable|numeric|min:0',
            'type' => 'required|in:service,article',
            'origin_country' => 'nullable|exists:import_countries,code',
            'weight_category' => 'sometimes|in:' . implode(',', Product::WEIGHT_CATEGORIES),
            'stock' => 'required|integer|min:0',
            'status' => 'required|in:active,inactive',
            'images.*' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:2048',

            'is_wholesale'         => 'nullable|boolean',
            'tiers'                => 'nullable|array',
            'tiers.*.label'        => 'required_with:tiers|string|max:255',
            'tiers.*.unit_price'   => 'required_with:tiers|numeric|min:0',
            'tiers.*.min_quantity' => 'required_with:tiers|integer|min:1',
            'tiers.*.pack_size'    => 'nullable|integer|min:1',
        ]);

        // Isole les données "gros" AVANT toute insertion — elles ne vont pas dans `products`
        $tiers = $validated['tiers'] ?? [];
        $isWholesale = $request->boolean('is_wholesale');
        unset($validated['tiers'], $validated['is_wholesale']);

        // Get shop owner
        $shop = Shop::findOrFail($validated['shop_id']);
        $validated['user_id'] = $shop->user_id;
        $validated['weight_category'] = $validated['weight_category'] ?? 'X-small';

        // Pays d'origine (produits importés). Vide = produit local.
        $validated['origin_country'] = $request->filled('origin_country')
            ? strtoupper($request->input('origin_country'))
            : null;

        // Generate slug
        $validated['slug'] = Str::slug($validated['name']);

        // Vente en gros
        $validated['is_wholesale'] = $isWholesale;

        // Create product
        $product = Product::create($validated);

        // Paliers de prix (module GROS)
        $this->syncPriceTiers($product, $tiers);

        // Handle images upload
        if ($request->hasFile('images')) {
            $this->uploadImages($product, $request->file('images'));
        }

        return redirect()->route('admin.products.index')->with('success', 'Produit créé avec succès!');
    }

    /**
     * Display the specified product
     */
    public function show(Product $product)
    {
        $product->load(['shop', 'category', 'subcategory', 'images', 'user', 'priceTiers']);

        return view('admin.products.show', compact('product'));
    }

    /**
     * Show the form for editing the specified product
     */
    public function edit(Product $product)
    {
        $product->load(['images', 'priceTiers']);
        $shops = Shop::where('status', 'active')->orderBy('name')->get();
        $categories = Category::orderBy('name')->get();
        $subcategories = Subcategory::with('category')->orderBy('name')->get();
        $importCountries = ImportCountry::activeOrdered()->get();

        return view('admin.products.edit', compact('product', 'shops', 'categories', 'subcategories', 'importCountries'));
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
            'price_type' => 'required|in:fixed,variable',
            'price' => 'required_if:price_type,fixed|nullable|numeric|min:0',
            'min_price' => 'required_if:price_type,variable|nullable|numeric|min:0',
            'max_price' => 'required_if:price_type,variable|nullable|numeric|min:0',
            'type' => 'required|in:service,article',
            'origin_country' => 'nullable|exists:import_countries,code',
            'weight_category' => 'sometimes|in:' . implode(',', Product::WEIGHT_CATEGORIES),
            'stock' => 'required|integer|min:0',
            'status' => 'required|in:active,inactive',
            'images.*' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:2048',

            'is_wholesale'         => 'nullable|boolean',
            'tiers'                => 'nullable|array',
            'tiers.*.label'        => 'required_with:tiers|string|max:255',
            'tiers.*.unit_price'   => 'required_with:tiers|numeric|min:0',
            'tiers.*.min_quantity' => 'required_with:tiers|integer|min:1',
            'tiers.*.pack_size'    => 'nullable|integer|min:1',
        ]);

        // Isole les données "gros" AVANT l'update — elles ne vont pas dans `products`
        $tiers = $validated['tiers'] ?? [];
        $isWholesale = $request->boolean('is_wholesale');
        unset($validated['tiers'], $validated['is_wholesale']);

        // Get shop owner
        $shop = Shop::findOrFail($validated['shop_id']);
        $validated['user_id'] = $shop->user_id;

        // Pays d'origine (produits importés). Vide = produit local (repasse à null).
        $validated['origin_country'] = $request->filled('origin_country')
            ? strtoupper($request->input('origin_country'))
            : null;

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

        // Handle new images upload
        if ($request->hasFile('images')) {
            $this->uploadImages($product, $request->file('images'));
        }

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

        $product->delete();

        return redirect()->route('admin.products.index')->with('success', 'Produit supprimé avec succès!');
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
                'currency'     => 'XAF',
                'is_active'    => true,
                'sort_order'   => $i + 1,
            ]);
        }
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
     * Get subcategories for a category (AJAX)
     */
    public function getSubcategories(Category $category)
    {
        return response()->json($category->subcategories);
    }
}
