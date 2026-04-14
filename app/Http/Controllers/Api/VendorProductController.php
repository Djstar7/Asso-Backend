<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use App\Models\DeliveryPricelist;

class VendorProductController extends Controller
{
    /**
     * Get all products for the authenticated vendor
     */
    public function index(Request $request)
    {
        $user = $request->user();

        $query = Product::with(['images', 'primaryImage', 'category', 'subcategory', 'shop'])
            ->where('user_id', $user->id)
            ->orderBy('created_at', 'desc');

        // Filter by status if provided
        if ($request->has('status') && $request->status) {
            $query->where('status', $request->status);
        }

        $perPage = $request->get('per_page', 20);
        $products = $query->paginate($perPage);

        $productsData = $products->getCollection()->map(function ($product) {
            return $this->formatProduct($product);
        });

        \Log::info('[VENDOR_PRODUCTS] Products fetched:', [
            'user_id' => $user->id,
            'total' => $products->total(),
            'current_page' => $products->currentPage(),
        ]);

        return response()->json([
            'success' => true,
            'data' => $productsData,
            'meta' => [
                'current_page' => $products->currentPage(),
                'last_page' => $products->lastPage(),
                'per_page' => $products->perPage(),
                'total' => $products->total(),
                'has_more' => $products->hasMorePages(),
            ],
        ]);
    }

    /**
     * Update a product
     */
    public function update(Request $request, $id)
    {
        $user = $request->user();
        $product = Product::findOrFail($id);

        // Verify product belongs to user
        if ($product->user_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'êtes pas autorisé à modifier ce produit',
            ], 403);
        }

        // Validate input
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'description' => 'sometimes|string',
            'price' => 'sometimes|numeric|min:0',
            'category_id' => 'sometimes|exists:categories,id',
            'subcategory_id' => 'sometimes|nullable|exists:subcategories,id',
            'type' => 'sometimes|in:article,service',
            'condition' => 'sometimes|in:new,used,refurbished',
            'weight_category' => 'sometimes|in:' . implode(',', Product::WEIGHT_CATEGORIES),
            'stock' => 'sometimes|integer|min:0',
            'weight' => 'sometimes|nullable|string|max:255',
            'images' => 'sometimes|array',
            'images.*' => 'file|image|mimes:jpeg,png,jpg,gif|max:5120',
        ]);

        try {
            // Check if new images are being added
            $totalNewImageSizeMb = 0;
            if ($request->hasFile('images')) {
                $vendorPackage = $user->activeVendorPackage;
                if (!$vendorPackage) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Vous devez avoir un package actif pour ajouter des images',
                        'error_code' => 'NO_ACTIVE_PACKAGE',
                    ], 403);
                }

                // Calculate size of new images
                foreach ($request->file('images') as $image) {
                    $totalNewImageSizeMb += $image->getSize() / 1048576;
                }

                // Check if enough storage
                if (!$vendorPackage->hasEnoughStorage($totalNewImageSizeMb)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Espace de stockage insuffisant pour ajouter ces images',
                        'error_code' => 'INSUFFICIENT_STORAGE',
                        'required_mb' => round($totalNewImageSizeMb, 2),
                        'available_mb' => round($vendorPackage->storage_remaining_mb, 2),
                    ], 403);
                }
            }

            // Update product fields
            $product->update(array_filter($validated, function ($key) {
                return $key !== 'images';
            }, ARRAY_FILTER_USE_KEY));

            // Add new images if provided
            if ($request->hasFile('images')) {
                $hasPrimaryImage = $product->images()->where('is_primary', true)->exists();

                foreach ($request->file('images') as $image) {
                    $path = $image->store('products', 'public');

                    $product->images()->create([
                        'image_path' => $path,
                        'is_primary' => !$hasPrimaryImage,
                    ]);

                    $hasPrimaryImage = true;
                }

                // Deduct storage from package
                $vendorPackage->deductStorage($totalNewImageSizeMb);
            }

            // Load relations for response
            $product->load(['images', 'primaryImage', 'category', 'subcategory', 'shop']);

            return response()->json([
                'success' => true,
                'message' => 'Produit mis à jour avec succès',
                'product' => $this->formatProduct($product),
            ]);
        } catch (\Exception $e) {
            \Log::error('[VENDOR_PRODUCT_UPDATE] Error:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la mise à jour du produit',
                'error' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Delete a product
     */
    public function destroy(Request $request, $id)
    {
        $user = $request->user();
        $product = Product::with('images')->findOrFail($id);

        // Verify product belongs to user
        if ($product->user_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'êtes pas autorisé à supprimer ce produit',
            ], 403);
        }

        try {
            // Calculate total image size to restore to package
            $totalImageSizeMb = 0;
            foreach ($product->images as $image) {
                $imagePath = $image->image_path;

                // Clean the path
                $cleanPath = str_starts_with($imagePath, 'storage/')
                    ? substr($imagePath, 8)
                    : $imagePath;

                // Get file size if exists
                if (Storage::disk('public')->exists($cleanPath)) {
                    $totalImageSizeMb += Storage::disk('public')->size($cleanPath) / 1048576;

                    // Delete the physical file
                    Storage::disk('public')->delete($cleanPath);
                }
            }

            // Delete product (images will be deleted via cascade or manually)
            $product->images()->delete();
            $product->delete();

            // Restore storage to vendor package
            $vendorPackage = $user->activeVendorPackage;
            if ($vendorPackage && $totalImageSizeMb > 0) {
                $vendorPackage->addStorage($totalImageSizeMb);
            }

            return response()->json([
                'success' => true,
                'message' => 'Produit supprimé avec succès',
                'storage_restored_mb' => round($totalImageSizeMb, 2),
            ]);
        } catch (\Exception $e) {
            \Log::error('[VENDOR_PRODUCT_DELETE] Error:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la suppression du produit',
                'error' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Format product for API response
     */
    private function formatProduct($product): array
    {
        return [
            'id' => $product->id,
            'name' => $product->name,
            'slug' => $product->slug,
            'description' => $product->description,
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
            'shop' => $product->shop ? [
                'id' => $product->shop->id,
                'name' => $product->shop->name,
                'slug' => $product->shop->slug,
                'logo' => $product->shop->logo,
            ] : null,
            'created_at' => $product->created_at->toIso8601String(),
            'updated_at' => $product->updated_at->toIso8601String(),
        ];
    }

    /**
     * Helper to get correct image URL
     */
    private function getImageUrl($imagePath)
    {
        if (empty($imagePath)) {
            return null;
        }

        $cleanPath = str_starts_with($imagePath, 'storage/')
            ? substr($imagePath, 8)
            : $imagePath;

        return asset('storage/' . $cleanPath);
    }
}
