<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Inventory;
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
            'deleted_image_ids' => 'sometimes|array',
            'deleted_image_ids.*' => 'integer|exists:product_images,id',
        ]);

        \Log::info('[VENDOR_PRODUCT_UPDATE] Received data:', [
            'product_id' => $id,
            'request_all' => $request->all(),
            'validated' => $validated,
        ]);

        try {
            // Initialize variables
            $totalNewImageSizeMb = 0;
            $deletedStorageMb = 0;

            // Check if new images are being added
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

            // Update product fields (exclude images from update)
            $updateData = $validated;
            unset($updateData['images']);

            \Log::info('[VENDOR_PRODUCT_UPDATE] Updating product:', [
                'product_id' => $product->id,
                'update_data' => $updateData,
                'before' => [
                    'name' => $product->name,
                    'weight' => $product->weight,
                    'stock' => $product->stock,
                ],
            ]);

            $product->update($updateData);

            \Log::info('[VENDOR_PRODUCT_UPDATE] Product updated:', [
                'product_id' => $product->id,
                'after' => [
                    'name' => $product->name,
                    'weight' => $product->weight,
                    'stock' => $product->stock,
                ],
            ]);

            // Delete specified images if provided
            if ($request->has('deleted_image_ids') && is_array($request->deleted_image_ids)) {
                \Log::info('[VENDOR_PRODUCT_UPDATE] Deleting images:', [
                    'image_ids' => $request->deleted_image_ids,
                ]);

                foreach ($request->deleted_image_ids as $imageId) {
                    $image = ProductImage::where('id', $imageId)
                        ->where('product_id', $product->id)
                        ->first();

                    if ($image) {
                        $imagePath = $image->image_path;

                        // Clean the path
                        $cleanPath = str_starts_with($imagePath, 'storage/')
                            ? substr($imagePath, 8)
                            : $imagePath;

                        // Get file size if exists
                        if (Storage::disk('public')->exists($cleanPath)) {
                            $deletedStorageMb += Storage::disk('public')->size($cleanPath) / 1048576;

                            // Delete the physical file
                            Storage::disk('public')->delete($cleanPath);
                        }

                        // Delete the database record
                        $image->delete();

                        \Log::info('[VENDOR_PRODUCT_UPDATE] Image deleted:', [
                            'image_id' => $imageId,
                            'path' => $cleanPath,
                        ]);
                    }
                }

                // Restore storage to vendor package
                if ($deletedStorageMb > 0) {
                    $vendorPackage = $user->activeVendorPackage;
                    if ($vendorPackage) {
                        $vendorPackage->addStorage($deletedStorageMb);
                        \Log::info('[VENDOR_PRODUCT_UPDATE] Storage restored:', [
                            'restored_mb' => round($deletedStorageMb, 2),
                        ]);
                    }
                }

                // Ensure at least one primary image exists after deletions
                $hasPrimary = $product->images()->where('is_primary', true)->exists();
                if (!$hasPrimary) {
                    $firstImage = $product->images()->first();
                    if ($firstImage) {
                        $firstImage->update(['is_primary' => true]);
                        \Log::info('[VENDOR_PRODUCT_UPDATE] Set first remaining image as primary:', [
                            'image_id' => $firstImage->id,
                        ]);
                    }
                }
            }

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

            $responseData = [
                'success' => true,
                'message' => 'Produit mis à jour avec succès',
                'product' => $this->formatProduct($product),
            ];

            // Add storage info if images were deleted or added
            if ($deletedStorageMb > 0 || $totalNewImageSizeMb > 0) {
                $vendorPackage = $user->activeVendorPackage;
                if ($vendorPackage) {
                    $responseData['storage_info'] = [
                        'deleted_mb' => round($deletedStorageMb, 2),
                        'added_mb' => round($totalNewImageSizeMb, 2),
                        'used_mb' => round($vendorPackage->storage_used_mb, 2),
                        'remaining_mb' => round($vendorPackage->storage_remaining_mb, 2),
                    ];
                }
            }

            return response()->json($responseData);
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

    /**
     * Get inventory for the authenticated vendor
     */
    public function getInventory(Request $request)
    {
        $user = $request->user();

        $query = Inventory::with(['product'])
            ->forUser($user->id)
            ->orderBy('created_at', 'desc');

        // Filter by type if provided
        if ($request->has('type') && $request->type) {
            $query->where('type', $request->type);
        }

        // Filter by product if provided
        if ($request->has('product_id') && $request->product_id) {
            $query->forProduct($request->product_id);
        }

        $perPage = $request->get('per_page', 50);
        $inventory = $query->paginate($perPage);

        $inventoryData = $inventory->getCollection()->map(function ($entry) {
            return [
                'id' => $entry->id,
                'product_id' => $entry->product_id,
                'product_name' => $entry->product->name ?? 'Produit supprimé',
                'type' => $entry->type,
                'quantity' => $entry->quantity,
                'stock_after' => $entry->stock_after,
                'order_id' => $entry->order_id,
                'notes' => $entry->notes,
                'date' => $entry->created_at->toIso8601String(),
                'created_at' => $entry->created_at->toIso8601String(),
            ];
        });

        return response()->json([
            'success' => true,
            'inventory' => $inventoryData,
            'pagination' => [
                'current_page' => $inventory->currentPage(),
                'last_page' => $inventory->lastPage(),
                'per_page' => $inventory->perPage(),
                'total' => $inventory->total(),
                'has_more' => $inventory->hasMorePages(),
            ],
        ]);
    }

    /**
     * Add an inventory entry
     */
    public function addInventoryEntry(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            'type' => 'required|in:entry,exit',
            'quantity' => 'required|integer|min:1',
            'notes' => 'nullable|string',
        ]);

        // Verify product belongs to user
        $product = Product::findOrFail($validated['product_id']);
        if ($product->user_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'êtes pas autorisé à gérer ce produit',
            ], 403);
        }

        DB::beginTransaction();
        try {
            // Calculate new stock
            $currentStock = $product->stock;
            $quantity = $validated['quantity'];

            if ($validated['type'] === 'entry') {
                $newStock = $currentStock + $quantity;
            } else {
                if ($currentStock < $quantity) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Stock insuffisant',
                    ], 400);
                }
                $newStock = $currentStock - $quantity;
            }

            // Update product stock
            $product->stock = $newStock;
            $product->save();

            // Create inventory entry
            $inventory = Inventory::create([
                'product_id' => $validated['product_id'],
                'user_id' => $user->id,
                'type' => $validated['type'],
                'quantity' => $quantity,
                'stock_after' => $newStock,
                'notes' => $validated['notes'] ?? null,
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Entrée d\'inventaire ajoutée avec succès',
                'inventory' => [
                    'id' => $inventory->id,
                    'product_id' => $inventory->product_id,
                    'product_name' => $product->name,
                    'type' => $inventory->type,
                    'quantity' => $inventory->quantity,
                    'stock_after' => $inventory->stock_after,
                    'notes' => $inventory->notes,
                    'date' => $inventory->created_at->toIso8601String(),
                ],
                'product' => [
                    'id' => $product->id,
                    'stock' => $product->stock,
                ],
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('[INVENTORY] Error adding entry:', [
                'error' => $e->getMessage(),
                'user_id' => $user->id,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l\'ajout de l\'entrée d\'inventaire',
            ], 500);
        }
    }

    /**
     * Update product stock directly
     */
    public function updateStock(Request $request, $id)
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

        $validated = $request->validate([
            'stock' => 'required|integer|min:0',
            'notes' => 'nullable|string',
        ]);

        DB::beginTransaction();
        try {
            $oldStock = $product->stock;
            $newStock = $validated['stock'];
            $difference = $newStock - $oldStock;

            if ($difference !== 0) {
                // Create inventory entry
                $type = $difference > 0 ? 'entry' : 'exit';
                $quantity = abs($difference);

                Inventory::create([
                    'product_id' => $product->id,
                    'user_id' => $user->id,
                    'type' => $type,
                    'quantity' => $quantity,
                    'stock_after' => $newStock,
                    'notes' => $validated['notes'] ?? 'Mise à jour manuelle du stock',
                ]);
            }

            // Update product stock
            $product->stock = $newStock;
            $product->save();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Stock mis à jour avec succès',
                'product' => [
                    'id' => $product->id,
                    'name' => $product->name,
                    'stock' => $product->stock,
                ],
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('[INVENTORY] Error updating stock:', [
                'error' => $e->getMessage(),
                'product_id' => $id,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la mise à jour du stock',
            ], 500);
        }
    }
}
