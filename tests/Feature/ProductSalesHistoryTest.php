<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Services\CommissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Supprimer un produit déjà vendu ne doit jamais effacer l'historique des ventes. */
class ProductSalesHistoryTest extends TestCase
{
    use RefreshDatabase;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();
        // Compte plateforme : propriétaire des boutiques ASSO.
        User::factory()->create(['email' => 'admin@asso.com', 'role' => 'admin']);
    }

    private function product(?User $owner = null): Product
    {
        $owner ??= CommissionService::platformAccount();
        $shop = Shop::create(['user_id' => $owner->id, 'name' => 'Boutique', 'slug' => 'b-'.uniqid(), 'status' => 'active']);
        $this->category = Category::create(['name' => 'Électronique', 'slug' => 'elec-'.uniqid()]);

        return Product::create([
            'shop_id' => $shop->id, 'user_id' => $owner->id, 'category_id' => $this->category->id,
            'name' => 'Téléphone', 'price' => 1000, 'stock' => 5, 'status' => 'active',
        ]);
    }

    private function sell(Product $product): OrderItem
    {
        $order = Order::create(['user_id' => User::factory()->create()->id, 'status' => 'delivered',
            'subtotal' => 1100, 'total' => 1100, 'payment_status' => 'paid']);

        return $order->items()->create(['product_id' => $product->id, 'seller_id' => $product->user_id,
            'quantity' => 1, 'unit_price' => 1100, 'total_price' => 1100, 'seller_unit_price' => 1000,
            'seller_total_price' => 1000, 'commission_rate' => 10]);
    }

    public function test_order_item_freezes_product_name_and_category(): void
    {
        $product = $this->product();
        $item = $this->sell($product);

        $this->assertSame('Téléphone', $item->product_name);
        $this->assertSame($this->category->id, $item->category_id);

        // La rubrique de la vente ne suit pas un changement de catégorie.
        $other = Category::create(['name' => 'Mode', 'slug' => 'mode-'.uniqid()]);
        $product->update(['category_id' => $other->id]);
        $this->assertSame($this->category->id, $item->fresh()->category_id);
    }

    public function test_admin_deleting_a_sold_product_archives_it(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $product = $this->product();
        $item = $this->sell($product);

        $this->actingAs($admin)->delete(route('admin.products.destroy', $product))
            ->assertRedirect(route('admin.products.index'));

        $this->assertSoftDeleted('products', ['id' => $product->id]);
        $this->assertDatabaseHas('order_items', ['id' => $item->id, 'product_id' => $product->id]);
        // La commande affiche toujours le produit archivé.
        $this->assertSame('Téléphone', $item->fresh()->product->name);
        $this->assertNull(Product::find($product->id));
    }

    public function test_admin_deleting_an_unsold_product_removes_it(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $product = $this->product();

        $this->actingAs($admin)->delete(route('admin.products.destroy', $product));

        $this->assertDatabaseMissing('products', ['id' => $product->id]);
    }

    public function test_vendor_deleting_a_sold_product_archives_it(): void
    {
        $vendor = User::factory()->create(['role' => 'vendeur', 'roles' => ['vendeur']]);
        $product = $this->product($vendor);
        $item = $this->sell($product);

        $this->actingAs($vendor, 'sanctum')->deleteJson("/api/v1/vendor/products/{$product->id}")
            ->assertOk();

        $this->assertSoftDeleted('products', ['id' => $product->id]);
        $this->assertDatabaseHas('order_items', ['id' => $item->id]);
    }

    public function test_creator_is_recorded_from_the_signed_in_account(): void
    {
        $manager = User::factory()->create(['role' => 'product_manager', 'roles' => ['product_manager']]);
        $this->actingAs($manager);

        $product = $this->product();

        $this->assertSame($manager->id, $product->created_by);
    }
}
