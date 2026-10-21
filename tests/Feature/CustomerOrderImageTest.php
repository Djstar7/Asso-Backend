<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerOrderImageTest extends TestCase
{
    use RefreshDatabase;

    public function test_order_images_fall_back_to_the_first_photo_and_prefer_the_primary_photo(): void
    {
        $buyer = User::factory()->create();
        $seller = User::factory()->create();
        $product = Product::create([
            'user_id' => $seller->id,
            'category_id' => Category::create(['name' => 'Maison', 'slug' => 'maison'])->id,
            'name' => 'Ventilateur', 'slug' => 'ventilateur', 'price' => 1000,
            'currency' => 'XAF', 'stock' => 10, 'status' => 'active',
        ]);
        $order = Order::create([
            'user_id' => $buyer->id, 'status' => 'shipped', 'payment_status' => 'paid',
            'subtotal' => 1000, 'delivery_fee' => 0, 'total' => 1000,
        ]);
        $order->items()->create([
            'product_id' => $product->id, 'seller_id' => $seller->id,
            'quantity' => 1, 'unit_price' => 1000, 'total_price' => 1000,
        ]);
        $this->actingAs($buyer, 'sanctum');

        $this->assertOrderImage($order, null);
        $product->images()->create(['image_path' => 'products/first.jpg', 'is_primary' => false, 'order' => 1]);
        $this->assertOrderImage($order, media_url('products/first.jpg'));

        $primary = $product->images()->create([
            'image_path' => 'https://cdn.example.com/primary.jpg', 'is_primary' => true, 'order' => 2,
        ]);
        $this->assertOrderImage($order, 'https://cdn.example.com/primary.jpg');

        $primary->update(['image_path' => '']);
        $this->assertOrderImage($order, media_url('products/first.jpg'));
    }

    private function assertOrderImage(Order $order, ?string $url): void
    {
        $this->getJson('/api/v1/orders')->assertOk()->assertJsonPath('orders.0.items.0.product_image', $url);
        $this->getJson("/api/v1/orders/{$order->id}")->assertOk()->assertJsonPath('order.items.0.product_image', $url);
    }
}
