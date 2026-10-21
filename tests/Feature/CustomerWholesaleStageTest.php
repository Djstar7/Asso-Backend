<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\Support\WholesaleOrderStage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerWholesaleStageTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_list_details_and_filters_follow_the_admin_wholesale_stages(): void
    {
        $buyer = User::factory()->create();
        $order = Order::create([
            'user_id' => $buyer->id, 'is_wholesale' => true,
            'status' => 'pending', 'payment_status' => 'pending',
            'payment_method' => 'stripe_direct',
            'subtotal' => 1000, 'delivery_fee' => 0, 'total' => 1000,
        ]);
        $this->actingAs($buyer, 'sanctum');

        foreach ([
            ['pending', 'pending', null, 'awaiting_payment'],
            ['pending', 'paid', null, 'to_validate'],
            ['confirmed', 'paid', 'confirmed', 'to_ship'],
            ['preparing', 'paid', 'preparing', 'to_ship'],
            ['shipped', 'paid', 'customs', 'in_transit'],
            ['shipped', 'paid', 'arrived_hub', 'last_mile'],
            ['shipped', 'paid', 'out_for_delivery', 'last_mile'],
            ['delivered', 'paid', 'delivered', 'delivered'],
            ['cancelled', 'paid', 'cancelled', 'cancelled'],
        ] as [$status, $payment, $tracking, $stage]) {
            $order->update(['status' => $status, 'payment_status' => $payment, 'tracking_status' => $tracking]);
            $this->assertSame($stage, WholesaleOrderStage::of($order));
            $this->getJson('/api/v1/orders')->assertOk()
                ->assertJsonPath('orders.0.wholesale_stage', $stage)
                ->assertJsonPath('orders.0.is_wholesale', true);
            $this->getJson("/api/v1/orders/{$order->id}")->assertOk()
                ->assertJsonPath('order.wholesale_stage', $stage);
            $this->getJson("/api/v1/orders?status={$stage}")->assertOk()
                ->assertJsonCount(1, 'orders');
        }

        $order->update(['is_wholesale' => false, 'status' => 'pending', 'payment_status' => 'paid']);
        $this->getJson('/api/v1/orders')->assertOk()->assertJsonPath('orders.0.wholesale_stage', null);
        $this->getJson('/api/v1/orders?status=to_validate')->assertOk()->assertJsonCount(0, 'orders');
        $order->update(['payment_status' => 'pending']);
        $this->getJson('/api/v1/orders')->assertOk()->assertJsonCount(0, 'orders');
    }
}
