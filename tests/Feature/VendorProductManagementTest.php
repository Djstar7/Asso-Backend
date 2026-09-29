<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\OrderItem;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class VendorProductManagementTest extends TestCase
{
    use RefreshDatabase;

    private function productFor(User $vendor, string $name = 'Produit test'): Product
    {
        $shop = Shop::create([
            'user_id' => $vendor->id,
            'name' => 'Boutique '.$vendor->id,
            'slug' => 'boutique-'.$vendor->id,
            'status' => 'active',
        ]);
        $category = Category::create([
            'name' => 'Catégorie '.$vendor->id,
            'slug' => 'categorie-'.$vendor->id,
        ]);

        return Product::create([
            'user_id' => $vendor->id,
            'shop_id' => $shop->id,
            'category_id' => $category->id,
            'name' => $name,
            'price' => 10000,
            'stock' => 5,
            'status' => 'active',
        ]);
    }

    public function test_vendor_can_deactivate_and_reactivate_own_product(): void
    {
        $vendor = User::factory()->create(['role' => 'vendeur']);
        $product = $this->productFor($vendor);
        Sanctum::actingAs($vendor);

        $this->putJson("/api/v1/vendor/products/{$product->id}/status", ['status' => 'inactive'])
            ->assertOk()
            ->assertJsonPath('product.status', 'inactive');

        $this->putJson("/api/v1/vendor/products/{$product->id}/status", ['status' => 'active'])
            ->assertOk()
            ->assertJsonPath('product.status', 'active');
    }

    public function test_vendor_can_set_and_clear_barcode_and_brand(): void
    {
        $vendor = User::factory()->create(['role' => 'vendeur']);
        $product = $this->productFor($vendor);
        Sanctum::actingAs($vendor);

        $this->putJson("/api/v1/vendor/products/{$product->id}", ['barcode' => '3017620422003', 'brand' => 'Nutella'])
            ->assertOk();
        $this->assertSame(['3017620422003', 'Nutella'], [$product->fresh()->barcode, $product->fresh()->brand]);

        $this->putJson("/api/v1/vendor/products/{$product->id}", ['barcode' => 'ABC123'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('barcode');

        $this->putJson("/api/v1/vendor/products/{$product->id}", ['barcode' => null, 'brand' => null])
            ->assertOk();
        $this->assertNull($product->fresh()->barcode);
        $this->assertNull($product->fresh()->brand);
    }

    public function test_vendor_cannot_change_another_vendors_product_status(): void
    {
        $owner = User::factory()->create(['role' => 'vendeur']);
        $otherVendor = User::factory()->create(['role' => 'vendeur']);
        $product = $this->productFor($owner);
        Sanctum::actingAs($otherVendor);

        $this->putJson("/api/v1/vendor/products/{$product->id}/status", ['status' => 'inactive'])
            ->assertNotFound();

        $this->assertSame('active', $product->fresh()->status);
    }

    public function test_restoring_an_order_item_restores_global_and_variant_stock(): void
    {
        $vendor = User::factory()->create(['role' => 'vendeur']);
        $product = $this->productFor($vendor);
        $variant = $product->variants()->create([
            'attributes' => ['Couleur' => 'Bleu', 'Pointure' => '42'],
            'stock' => 2,
        ]);
        $item = new OrderItem([
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'quantity' => 3,
        ]);
        $item->setRelation('product', $product);
        $item->setRelation('variant', $variant);

        $item->restoreStock();

        $this->assertSame(8, $product->fresh()->stock);
        $this->assertSame(5, $variant->fresh()->stock);
    }
}
