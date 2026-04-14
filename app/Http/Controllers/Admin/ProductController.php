<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Category;
use App\Models\Subcategory;
use App\Models\Shop;
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

        // Filter by shop
        if ($request->filled('shop_id')) {
            $query->where('shop_id', $request->shop_id);
        }

        // Filter by category
        if ($request->filled('category_id')) {
            $query->where('category_id', $request->category_id);
        }

        // Filter by type
        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        // Filter by price type
        if ($request->filled('price_type')) {
            $query->where('price_type', $request->price_type);
        }

        // Filter by status
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // Search
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

        return view('admin.products.create', compact('shops', 'categories', 'subcategories'));
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
            'weight_category' => 'sometimes|in:' . implode(',', Product::WEIGHT_CATEGORIES),
            'stock' => 'required|integer|min:0',
            'status' => 'required|in:active,inactive',
            'images.*' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:2048',
        ]);

        // Get shop owner
        $shop = Shop::findOrFail($validated['shop_id']);
        $validated['user_id'] = $shop->user_id;
        $validated['weight_category'] = $validated['weight_category'] ?? 'X-small';

        // Generate slug
        $validated['slug'] = Str::slug($validated['name']);

        // Create product
        $product = Product::create($validated);

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
        $product->load(['shop', 'category', 'subcategory', 'images', 'user']);

        return view('admin.products.show', compact('product'));
    }

    /**
     * Show the form for editing the specified product
     */
    public function edit(Product $product)
    {
        $product->load('images');
        $shops = Shop::where('status', 'active')->orderBy('name')->get();
        $categories = Category::orderBy('name')->get();
        $subcategories = Subcategory::with('category')->orderBy('name')->get();

        return view('admin.products.edit', compact('product', 'shops', 'categories', 'subcategories'));
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
            'weight_category' => 'sometimes|in:' . implode(',', Product::WEIGHT_CATEGORIES),
            'stock' => 'required|integer|min:0',
            'status' => 'required|in:active,inactive',
            'images.*' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:2048',
        ]);

        // Get shop owner
        $shop = Shop::findOrFail($validated['shop_id']);
        $validated['user_id'] = $shop->user_id;

        // Update slug if name changed
        if ($product->name !== $validated['name']) {
            $validated['slug'] = Str::slug($validated['name']);
        }

        // Update product
        $product->update($validated);

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
        // Delete all product images
        foreach ($product->images as $image) {
            if (File::exists(public_path($image->image_path))) {
                File::delete(public_path($image->image_path));
            }
        }

        // Delete product (images will be cascade deleted)
        $product->delete();

        return redirect()->route('admin.products.index')->with('success', 'Produit supprimé avec succès!');
    }

    /**
     * Upload product images
     */
    private function uploadImages(Product $product, array $images)
    {
        $order = $product->images()->max('order') ?? 0;
        $isPrimary = $product->images()->count() === 0; // First image is primary

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

            $isPrimary = false; // Only first image is primary
        }
    }

    /**
     * Delete a specific product image
     */
    public function deleteImage(Request $request, Product $product, ProductImage $image)
    {
        // Verify image belongs to product
        if ($image->product_id !== $product->id) {
            return response()->json(['error' => 'Image not found'], 404);
        }

        // Delete physical file
        if (File::exists(public_path($image->image_path))) {
            File::delete(public_path($image->image_path));
        }

        // If this was primary image, set another one as primary
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
        // Verify image belongs to product
        if ($image->product_id !== $product->id) {
            return response()->json(['error' => 'Image not found'], 404);
        }

        // Remove primary from all images
        $product->images()->update(['is_primary' => false]);

        // Set this image as primary
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
