<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Notification;
use App\Models\Order;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class VendorOrderDetailsTest extends TestCase
{
    use RefreshDatabase;

    private User $vendor;
    private User $client;
    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();
        $this->vendor = User::factory()->create(['role' => 'vendeur']);
        $this->client = User::factory()->create(['role' => 'client', 'phone' => '+237600000000']);
        $shop = Shop::create(['user_id' => $this->vendor->id, 'name' => 'B', 'slug' => 'b', 'status' => 'active']);
        $category = Category::create(['name' => 'Chaussures', 'slug' => 'chaussures']);
        $product = Product::create([
            'user_id' => $this->vendor->id, 'shop_id' => $shop->id, 'category_id' => $category->id,
            'name' => 'Basket', 'price' => 10000, 'stock' => 3, 'status' => 'active',
        ]);
        $variant = $product->variants()->create([
            'attributes' => ['Couleur' => 'Noir', 'Pointure' => '42'], 'stock' => 1, 'price_adjustment' => 0,
        ]);

        $this->order = Order::create([
            'user_id' => $this->client->id,
            'status' => 'pending',
            'subtotal' => 10000,
            'total' => 11500,
            'delivery_fee' => 1500,
            'delivery_address' => 'Rue 1, Douala, Cameroun',
            'delivery_address_details' => 'Portail bleu',
            'customer_phone' => '+237699999999',
            'notes' => 'Appeler avant',
            'payment_method' => 'wallet_kpay',
            'payment_status' => 'paid',
        ]);
        $this->order->items()->create([
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'variant_attributes' => $variant->attributes,
            'seller_id' => $this->vendor->id,
            'quantity' => 1,
            'unit_price' => 10000,
            'total_price' => 10000,
        ]);
    }

    public function test_vendor_sees_everything_needed_to_prepare_the_order(): void
    {
        Sanctum::actingAs($this->vendor);

        $this->getJson("/api/v1/vendor/orders/{$this->order->id}")
            ->assertOk()
            ->assertJsonPath('order.order_number', $this->order->order_number)
            ->assertJsonPath('order.city', 'Douala')
            ->assertJsonPath('order.customer_phone', '+237699999999')
            ->assertJsonPath('order.delivery_address_details', 'Portail bleu')
            ->assertJsonPath('order.notes', 'Appeler avant')
            ->assertJsonPath('order.items.0.variant_label', 'Couleur : Noir · Pointure : 42');

        $this->getJson('/api/v1/vendor/orders')
            ->assertOk()
            ->assertJsonPath('orders.0.items.0.variant_attributes.Pointure', '42');
    }

    public function test_unpaid_card_attempts_are_hidden_from_the_vendor(): void
    {
        $abandoned = $this->order->replicate(['order_number']);
        $abandoned->fill(['payment_method' => 'stripe_direct', 'payment_status' => 'pending'])->save();
        $abandoned->items()->create([
            'product_id' => $this->order->items->first()->product_id,
            'seller_id' => $this->vendor->id,
            'quantity' => 1,
            'unit_price' => 10000,
            'total_price' => 10000,
        ]);

        Sanctum::actingAs($this->vendor);

        $ids = collect($this->getJson('/api/v1/vendor/orders')->assertOk()->json('orders'))->pluck('id');
        $this->assertTrue($ids->contains($this->order->id));
        $this->assertFalse($ids->contains($abandoned->id));
    }

    public function test_check_active_orders_endpoint_works(): void
    {
        Sanctum::actingAs($this->vendor);

        $this->getJson('/api/v1/vendor/orders/check-active')
            ->assertOk()
            ->assertJsonPath('has_active_orders', true)
            ->assertJsonPath('active_orders_count', 1);
    }

    public function test_other_vendors_cannot_open_the_order(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'vendeur']));

        $this->getJson("/api/v1/vendor/orders/{$this->order->id}")->assertNotFound();
    }

    public function test_seller_is_notified_with_string_data(): void
    {
        $this->order->notifySellers('Titre', 'Corps', ['type' => 'order_cancelled_vendor', 'cancel_reason' => null]);

        $notification = Notification::where('user_id', $this->vendor->id)->latest('id')->firstOrFail();
        $this->assertSame('order_cancelled_vendor', $notification->type);
        $this->assertSame((string) $this->order->id, $notification->data['order_id']);
    }
}
