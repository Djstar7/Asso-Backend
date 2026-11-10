<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\ImportCountry;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Services\FirebaseMessagingService;
use App\Services\OrderService;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SourcedCatalogVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private function product(): Product
    {
        $seller = User::factory()->create();
        $shop = Shop::create(['user_id' => $seller->id, 'name' => 'ASSO Import', 'status' => 'active', 'verified_at' => now()]);
        $category = Category::create(['name' => 'Maison', 'slug' => 'maison']);
        ImportCountry::create(['code' => 'CN', 'name' => 'Chine', 'is_active' => true]);
        $product = Product::create([
            'user_id' => $seller->id, 'shop_id' => $shop->id, 'category_id' => $category->id,
            'name' => 'Gourde inox', 'price' => 1500, 'currency' => 'XAF', 'type' => 'article',
            'origin_country' => 'CN', 'is_wholesale' => true, 'status' => 'active',
            'requires_quote' => true, 'stock' => 0,
        ]);
        $product->priceTiers()->create(['label' => 'Dès 10 pièces', 'unit_price' => 1500, 'currency' => 'XAF', 'min_quantity' => 10, 'pack_size' => 1, 'is_active' => true]);
        return $product;
    }

    public function test_reference_is_visible_without_a_confirmation_requirement(): void
    {
        $product = $this->product();
        $this->getJson('/api/v1/import/CN/products?page=1&category_id='.$product->category_id)
            ->assertOk()->assertJsonPath('products.0.id', $product->id)
            ->assertJsonPath('products.0.category.id', $product->category_id)
            ->assertJsonPath('products.0.requires_quote', false)
            ->assertJsonPath('products.0.price_is_indicative', false)
            ->assertJsonPath('products.0.availability_label', null)
            ->assertJsonPath('products.0.price_tiers.0.unit_price', 1500);
        $other = Category::create(['name' => 'Autre', 'slug' => 'autre']);
        $this->getJson('/api/v1/import/CN/products?page=1&category_id='.$other->id)
            ->assertOk()->assertJsonCount(0, 'products');
        $this->getJson('/api/v1/import/products/'.$product->id)->assertOk()
            ->assertJsonPath('product.requires_quote', false);
    }

    public function test_legacy_confirmation_flag_does_not_block_wholesale_order_validation(): void
    {
        $product = $this->product();
        $buyer = User::factory()->create();
        $this->mock(WalletService::class);
        $this->mock(FirebaseMessagingService::class);

        // L'option de livraison absente est vérifiée après les produits et les paliers.
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        app(OrderService::class)->createWholesaleOrder($buyer, [['product_id' => $product->id, 'quantity' => 10]], 999);
    }

    public function test_legacy_confirmation_flag_does_not_bypass_stock_validation(): void
    {
        $product = $this->product();
        $buyer = User::factory()->create();
        $this->mock(WalletService::class);
        $this->mock(FirebaseMessagingService::class);

        try {
            app(OrderService::class)->createOrder($buyer, [['product_id' => $product->id, 'quantity' => 1]], 999, null, 'wallet');
            $this->fail('A product with zero stock was orderable.');
        } catch (\Exception $exception) {
            $this->assertSame(__('orders.insufficient_stock', ['product' => $product->name, 'available' => 0]), $exception->getMessage());
        }
        $this->assertSame(0, DB::table('orders')->count());
    }
}
