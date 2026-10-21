<?php

namespace Tests\Feature;

use App\Models\DelivererCompany;
use App\Models\DeliveryCityGrid;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerOrderProgressTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_agency_options_and_last_mile_grids_match_the_filters(): void
    {
        $buyer = User::factory()->create();
        $partner = DelivererCompany::create([
            'name' => 'Transporteur', 'service_type' => DelivererCompany::SERVICE_INTERCITY,
            'service_mode' => DelivererCompany::MODE_AGENCY, 'is_active' => true,
        ]);
        $grid = DeliveryCityGrid::create([
            'deliverer_company_id' => $partner->id, 'city' => 'Yaoundé', 'country' => 'CM',
            'zones' => [], 'vehicles' => [], 'is_active' => true,
        ]);
        $order = Order::create([
            'user_id' => $buyer->id, 'delivery_company_id' => $partner->id,
            'delivery_mode' => Order::DELIVERY_CARRIER, 'status' => 'shipped',
            'tracking_status' => 'ready_for_pickup', 'payment_status' => 'paid',
            'subtotal' => 1000, 'delivery_fee' => 0, 'total' => 1000,
        ]);
        $this->actingAs($buyer, 'sanctum');
        foreach ([
            [[], null, true],
            [['service_mode' => DelivererCompany::MODE_AGENCY], null, true],
            [['delivery_option' => 'home_delivery'], null, false],
            [['service_mode' => 'door_to_door'], null, false],
            [['delivery_option' => 'agency_pickup'], $grid->id, false],
        ] as [$snapshot, $gridId, $agency]) {
            $order->update(['delivery_breakdown' => $snapshot, 'delivery_city_grid_id' => $gridId]);
            $this->getJson("/api/v1/orders/{$order->id}")->assertOk()
                ->assertJsonPath('order.progress.delivery_option', $agency ? 'agency_pickup' : 'home_delivery');
            $this->getJson('/api/v1/orders?status=ready_for_pickup')->assertOk()->assertJsonCount($agency ? 1 : 0, 'orders');
            $this->getJson('/api/v1/orders?status=shipped')->assertOk()->assertJsonCount($agency ? 0 : 1, 'orders');
        }
    }

    public function test_all_order_kinds_follow_their_admin_stages_and_delivery_mode(): void
    {
        $buyer = User::factory()->create();
        $order = Order::create([
            'user_id' => $buyer->id, 'status' => 'pending', 'payment_status' => 'paid',
            'payment_method' => 'wallet', 'subtotal' => 1000, 'delivery_fee' => 0, 'total' => 1000,
        ]);
        $this->actingAs($buyer, 'sanctum');

        foreach (['local', 'retail', 'wholesale'] as $kind) {
            foreach ([false, true] as $agency) {
                foreach ([
                    ['pending', 'pending', null],
                    ['pending', 'paid', null],
                    ['confirmed', 'paid', 'confirmed'],
                    ['preparing', 'paid', 'preparing'],
                    ['shipped', 'paid', 'handed_to_carrier'],
                    ['shipped', 'paid', 'arrived_hub'],
                    ['shipped', 'paid', 'arrived'],
                    ['shipped', 'paid', 'ready_for_pickup'],
                    ['delivered', 'paid', $agency ? 'collected_at_agency' : 'delivered'],
                    ['cancelled', 'paid', 'cancelled'],
                ] as [$status, $payment, $tracking]) {
                    $order->update([
                        'is_wholesale' => $kind === 'wholesale', 'has_local_products' => $kind === 'local',
                        'delivery_mode' => Order::DELIVERY_CARRIER,
                        'delivery_breakdown' => ['delivery_option' => $agency ? 'agency_pickup' : 'home_delivery'],
                        'status' => $status, 'payment_status' => $payment, 'tracking_status' => $tracking,
                    ]);
                    $stage = match ($status) {
                        'pending' => $payment === 'pending' ? 'awaiting_payment' : ($kind === 'wholesale' ? 'to_validate' : 'to_confirm'),
                        'confirmed', 'preparing' => $kind === 'wholesale' ? 'to_ship' : 'preparing',
                        'shipped' => $agency
                            ? ($tracking === 'ready_for_pickup' ? 'ready_for_pickup' : 'in_transit')
                            : ($kind === 'wholesale' ? (in_array($tracking, ['arrived_hub', 'arrived', 'ready_for_pickup'], true) ? 'last_mile' : 'in_transit') : 'shipped'),
                        default => $status,
                    };
                    $this->getJson("/api/v1/orders/{$order->id}")->assertOk()
                        ->assertJsonPath('order.progress.kind', $kind)
                        ->assertJsonPath('order.progress.stage', $stage)
                        ->assertJsonPath('order.progress.delivery_option', $agency ? 'agency_pickup' : 'home_delivery');
                    $list = $this->getJson('/api/v1/orders')->assertOk()
                        ->assertJsonPath('orders.0.progress.stage', $stage);
                    $steps = $list->json('orders.0.progress.steps');
                    $this->assertContains($stage, $steps);
                    if ($status !== 'cancelled') {
                        $this->assertSame($agency, in_array('ready_for_pickup', $steps, true));
                        $this->assertSame($kind === 'wholesale', in_array('to_validate', $steps, true));
                        if ($agency) {
                            $this->assertNotContains('last_mile', $steps);
                            $this->assertNotContains('shipped', $steps);
                        }
                    }
                    $this->getJson("/api/v1/orders?status={$stage}")->assertOk()->assertJsonCount(1, 'orders');
                    if ($stage !== 'ready_for_pickup') {
                        $this->getJson('/api/v1/orders?status=ready_for_pickup')->assertOk()->assertJsonCount(0, 'orders');
                    }
                }
            }
        }
    }
}
