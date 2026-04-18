<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Category;
use App\Models\DeliveryPricelist;
use App\Models\Inventory;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    /**
     * List products with pagination, filters, search
     */
    public function index(Request $request)
    {
        $query = Product::with(['images', 'primaryImage', 'category', 'subcategory', 'shop', 'user'])
            ->where('status', 'active')
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

        // Filter by price range
        if ($request->has('min_price') && $request->min_price) {
            $query->where('price', '>=', $request->min_price);
        }
        if ($request->has('max_price') && $request->max_price) {
            $query->where('price', '<=', $request->max_price);
        }

        // Sort
        $sortBy = $request->get('sort_by', 'created_at');
        $sortOrder = $request->get('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

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
        $products = Product::with(['images', 'primaryImage', 'category', 'subcategory', 'shop', 'user'])
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

        $products = Product::with(['images', 'primaryImage', 'category', 'subcategory', 'shop', 'user'])
            ->where('status', 'active')
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
        $product = Product::with(['images', 'primaryImage', 'category', 'subcategory', 'shop', 'user', 'reviews.user'])
            ->findOrFail($id);

        $favoriteIds = [];
        if ($request->user()) {
            $favoriteIds = \DB::table('favorites')
                ->where('user_id', $request->user()->id)
                ->pluck('product_id')
                ->toArray();
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
            'message' => $isFavorite ? 'Ajouté aux favoris' : 'Retiré des favoris',
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

        // Validate input
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'required|string',
            'price' => 'required|numeric|min:0',
            'category_id' => 'required|exists:categories,id',
            'subcategory_id' => 'nullable|exists:subcategories,id',
            'type' => 'required|in:article,service',
            'condition' => 'required|in:new,used,refurbished',
            'stock' => 'nullable|integer|min:0',
            'weight' => 'nullable|string|max:255',
            'weight_category' => 'nullable|in:' . implode(',', Product::WEIGHT_CATEGORIES),
            'images' => 'required|array|min:1',
            'images.*' => 'file|image|mimes:jpeg,png,jpg,gif|max:5120',
        ]);

        \Log::info('[PRODUCT_STORE] Validation passed');

        // 1. Verify that the user has an active package
        $vendorPackage = $request->user()->activeVendorPackage;
        if (!$vendorPackage) {
            return response()->json([
                'success' => false,
                'message' => 'Vous devez souscrire à un package de stockage pour ajouter des produits',
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
                'message' => 'Espace de stockage insuffisant. Veuillez souscrire à un package supplémentaire.',
                'error_code' => 'INSUFFICIENT_STORAGE',
                'required_mb' => round($totalImageSizeMb, 2),
                'available_mb' => round($vendorPackage->storage_remaining_mb, 2),
            ], 403);
        }

        \Log::info('[PRODUCT_STORE] Creating product...');

        // Get the user's shop (vendors should have a shop)
        $shop = $request->user()->shops()->first();
        if (!$shop) {
            \Log::warning('[PRODUCT_STORE] User has no shop');
            return response()->json([
                'success' => false,
                'message' => 'Vous devez créer une boutique avant d\'ajouter des produits',
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
            'category_id' => $validated['category_id'],
            'type' => $validated['type'],
            'condition' => $validated['condition'],
            'weight_category' => $validated['weight_category'] ?? 'X-small',
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

        $product = Product::create($productData);

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
        $product->load(['images', 'primaryImage', 'category', 'subcategory', 'user']);

        \Log::info('[PRODUCT_STORE] Product loaded with relations');
        \Log::info('========== PRODUCT STORE SUCCESS ==========');

        return response()->json([
            'success' => true,
            'message' => 'Product created successfully',
            'product' => $this->formatProduct($product, []),
            'storage_info' => [
                'used_mb' => round($totalImageSizeMb, 2),
                'remaining_mb' => round($vendorPackage->storage_remaining_mb, 2),
            ],
        ], 201);
    }

    /**
     * Format product for API response
     */
    private function formatProduct($product, $favoriteIds = [], $detailed = false): array
    {
        $data = [
            'id' => $product->id,
            'name' => $product->name,
            'slug' => $product->slug,
            'price' => (float) $product->price,
            'min_price' => $product->min_price ? (float) $product->min_price : null,
            'max_price' => $product->max_price ? (float) $product->max_price : null,
            'price_type' => $product->price_type ?? 'fixed',
            'formatted_price' => $product->formatted_price,
            'type' => $product->type ?? 'article',
            'weight_category' => $product->weight_category ?? 'X-small',
            'stock' => $product->stock,
            'weight' => $product->weight,
            'status' => $product->status,
            'latitude' => $product->latitude ? (float) $product->latitude : null,
            'longitude' => $product->longitude ? (float) $product->longitude : null,
            'is_favorite' => in_array($product->id, $favoriteIds),
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
                'latitude' => $product->shop->latitude ? (float) $product->shop->latitude : null,
                'longitude' => $product->shop->longitude ? (float) $product->shop->longitude : null,
                'address' => $product->shop->address,
            ] : null,
            'location' => $product->shop ? $product->shop->address : ($product->user ? $product->user->address : null),
            'created_at' => $product->created_at->toIso8601String(),
        ];

        if ($detailed) {
            $data['description'] = $product->description;
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

    /**
     * Helper to get correct image URL
     * Handles both 'products/image.jpg' and 'storage/products/image.jpg' formats
     */
    private function getImageUrl($imagePath)
    {
        if (empty($imagePath)) {
            return null;
        }

        // Remove 'storage/' prefix if it exists (to avoid double storage/ in URL)
        $cleanPath = str_starts_with($imagePath, 'storage/')
            ? substr($imagePath, 8)  // Remove 'storage/'
            : $imagePath;

        return asset('storage/' . $cleanPath);
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