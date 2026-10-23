<?php

namespace Tests\Feature;

use App\Models\DelivererCodeSync;
use App\Models\DelivererCompany;
use App\Models\DelivererSyncCode;
use App\Models\DeliveryAgency;
use App\Models\DeliveryRoute;
use App\Models\Order;
use App\Models\User;
use App\Services\AssoShipmentService;
use App\Services\CarrierCourierService;
use App\Services\FirebaseMessagingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CarrierCourierDispatchTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $buyer;
    private User $courier;
    private DelivererCompany $company;
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(FirebaseMessagingService::class, function ($mock) {
            $mock->shouldReceive('sendToUser')->andReturnUsing(function ($user, $title, $body, $data = []) {
                $this->sent[] = ['user' => $user->id, 'type' => $data['type'] ?? null];
                return ['success' => true];
            });
        });
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->buyer = User::factory()->create();
        $this->company = DelivererCompany::create(['name' => 'SOLEX', 'is_active' => true, 'service_type' => 'intercity']);
        $this->courier = $this->sync();
    }

    private function sync(bool $online = true, bool $banned = false, ?DelivererCompany $company = null): User
    {
        $company ??= $this->company;
        $user = User::factory()->create(['role' => 'livreur']);
        $code = DelivererSyncCode::create(['company_id' => $company->id, 'sync_code' => 'TEST-'.uniqid(), 'sent_via' => 'email', 'sent_at' => now(), 'expires_at' => now()->addDay()]);
        DelivererCodeSync::create(['sync_code_id' => $code->id, 'user_id' => $user->id, 'company_id' => $company->id,
            'is_active' => true, 'is_banned' => $banned, 'is_online' => $online, 'synced_at' => now()]);
        return $user;
    }

    private function order(bool $agency = false): Order
    {
        $route = DeliveryRoute::create(['deliverer_company_id' => $this->company->id, 'origin_country' => 'CM',
            'origin_city' => 'Douala', 'destination_country' => 'CM', 'destination_city' => 'Bafoussam', 'pricing_data' => [], 'is_active' => true]);
        $order = Order::create(['user_id' => $this->buyer->id, 'delivery_company_id' => $this->company->id,
            'delivery_mode' => 'carrier', 'delivery_route_id' => $route->id, 'is_wholesale' => true,
            'status' => 'shipped', 'payment_status' => 'paid', 'tracking_status' => 'arrived_hub',
            'delivery_address' => 'Banengo, Bafoussam', 'delivery_city' => 'Bafoussam', 'confirmation_code' => '123456',
            'subtotal' => 10000, 'total' => 12000, 'delivery_fee' => 2000,
            'vendor_funds_status' => Order::VENDOR_FUNDS_HELD,
            'delivery_breakdown' => ['delivery_option' => $agency ? 'agency_pickup' : 'home_delivery', 'destination_city' => 'Bafoussam']]);
        if ($agency) {
            $destination = DeliveryAgency::create(['deliverer_company_id' => $this->company->id, 'code' => 'SOLEX-BFS',
                'name' => 'SOLEX Bafoussam', 'address' => 'Centre-ville', 'city' => 'Bafoussam', 'phone' => '237670000001', 'is_active' => true]);
            $order->update(['delivery_agency_id' => $destination->id, 'pickup_agency_snapshot' => $destination->toPickupArray()]);
        }
        return $order;
    }

    private function request(Order $order): void
    {
        app(AssoShipmentService::class)->advance($order, ['step' => 'pickup_requested'], $this->admin);
    }

    private function courierStep(Order $order, string $step): void
    {
        $this->actingAs($this->courier, 'sanctum')->postJson("/api/v1/delivery/{$order->id}/carrier-step", ['step' => $step])->assertOk();
    }

    public function test_request_notifies_only_eligible_synced_couriers_and_appears_in_dashboard_once(): void
    {
        $offline = $this->sync(false);
        $banned = $this->sync(true, true);
        $foreign = $this->sync(company: DelivererCompany::create(['name' => 'Autre', 'is_active' => true]));
        $order = $this->order();
        $this->actingAs($this->courier, 'sanctum')->getJson('/api/v1/delivery/pending')->assertJsonCount(0, 'requests');
        $this->request($order);
        app(CarrierCourierService::class)->request($order->fresh());
        $recipients = collect($this->sent)->where('type', 'new_delivery_request')->pluck('user')->all();
        $this->assertSame([$this->courier->id], $recipients);
        $this->assertNotContains($offline->id, $recipients);
        $this->assertNotContains($banned->id, $recipients);
        $this->assertNotContains($foreign->id, $recipients);
        $this->getJson('/api/v1/delivery/pending')->assertJsonCount(1, 'requests')->assertJsonPath('requests.0.status', 'confirmed');
        $this->getJson('/api/v1/delivery/dashboard')->assertJsonPath('stats.pending', 1)
            ->assertJsonPath('deliveries.0.carrier_managed', true)->assertJsonPath('deliveries.0.can_complete', false);
        $this->assertDatabaseCount('wallet_transactions', 0);
    }

    public function test_tracking_placeholder_is_hidden_after_seller_confirmation(): void
    {
        $order = $this->order();
        $order->update(['status' => 'pending', 'confirmed_at' => null]);
        $this->actingAs($this->buyer, 'sanctum')->getJson("/api/v1/orders/{$order->id}")
            ->assertOk()->assertJsonPath('order.delivery.show_tracking_pending', true);
        $order->update(['status' => 'confirmed', 'confirmed_at' => now()]);
        $this->getJson("/api/v1/orders/{$order->id}")
            ->assertOk()->assertJsonPath('order.delivery.show_tracking_pending', false);
    }

    public function test_courier_distance_uses_import_hub_and_is_missing_without_coordinates(): void
    {
        $hub = \App\Support\ImportHub::ensureShop();
        $this->assertNotNull($hub);
        $hub->update(['latitude' => 4.04, 'longitude' => 9.70]);
        $order = $this->order();
        $order->update(['delivery_latitude' => 5.48, 'delivery_longitude' => 10.40,
            'delivery_breakdown' => array_merge($order->delivery_breakdown, ['pricing_type' => 'territorial_network'])]);
        $this->request($order);
        $this->actingAs($this->courier, 'sanctum');
        foreach (['/api/v1/delivery/pending' => 'requests', '/api/v1/delivery/dashboard' => 'deliveries'] as $url => $key) {
            $response = $this->getJson($url)->assertOk()
                ->assertJsonPath("$key.0.distance_type", 'straight_line')
                ->assertJsonPath("$key.0.pickup_latitude", 4.04)
                ->assertJsonPath("$key.0.pickup_longitude", 9.7);
            $this->assertEqualsWithDelta(178, $response->json("$key.0.distance"), 2);
        }
        $order->update(['delivery_latitude' => null]);
        $this->getJson('/api/v1/delivery/dashboard')->assertJsonPath('deliveries.0.distance', null);
        $this->getJson('/api/v1/delivery/pending')->assertJsonPath('requests.0.distance', null);
    }

    public function test_first_courier_accepts_then_tracks_and_hands_over_with_client_code_without_double_payment(): void
    {
        $other = $this->sync();
        $order = $this->order();
        $this->request($order);
        $this->actingAs($this->courier, 'sanctum')->postJson("/api/v1/delivery/{$order->id}/accept")->assertOk();
        $this->assertSame('pickup_accepted', $order->fresh()->tracking_status);
        $this->actingAs($this->buyer, 'sanctum')->getJson("/api/v1/orders/{$order->id}")
            ->assertOk()->assertJsonPath('order.confirmation_code', '123456');
        $this->getJson('/api/v1/orders')->assertOk()->assertJsonPath('orders.0.confirmation_code', '123456');
        $this->actingAs($this->courier, 'sanctum')->getJson('/api/v1/delivery/dashboard')
            ->assertOk()->assertJsonPath('deliveries.0.can_complete', false)
            ->assertJsonPath('deliveries.0.carrier_steps.0.key', 'collected_by_carrier');

        $this->actingAs($other, 'sanctum')->postJson("/api/v1/delivery/{$order->id}/accept")->assertStatus(422);
        $this->assertSame($this->courier->id, $order->fresh()->delivery_person_id);
        $this->actingAs($this->buyer, 'sanctum')->getJson("/api/v1/orders/{$order->id}")
            ->assertJsonPath('order.confirmation_code', '123456');
        $this->actingAs($this->courier, 'sanctum');
        $this->actingAs($this->courier, 'sanctum')->postJson("/api/v1/delivery/{$order->id}/complete", ['confirmation_code' => '123456'])->assertStatus(422);
        $this->postJson("/api/v1/delivery/{$order->id}/carrier-step", ['step' => 'delivered'])->assertStatus(422);
        foreach (['collected_by_carrier', 'partner_in_transit', 'arrived', 'out_for_delivery'] as $step) {
            $this->courierStep($order, $step);
        }
        $this->getJson('/api/v1/delivery/active')->assertJsonPath('deliveries.0.can_complete', true);
        $this->actingAs($this->buyer, 'sanctum')->getJson("/api/v1/orders/{$order->id}")->assertOk()->assertJsonPath('order.confirmation_code', '123456');
        $this->actingAs($this->courier, 'sanctum');
        $this->postJson("/api/v1/delivery/{$order->id}/complete", ['confirmation_code' => '000000'])->assertStatus(422);
        $this->postJson("/api/v1/delivery/{$order->id}/complete", ['confirmation_code' => '123456'])->assertOk();
        $this->assertSame('delivered', $order->fresh()->status);
        $this->assertNotNull($order->fresh()->auto_validate_at);
        $this->assertNull($order->fresh()->confirmed_by_client_at);
        $this->assertSame('deliverer', $order->trackingEvents()->where('step', 'delivered')->firstOrFail()->actor_type);
        $this->postJson("/api/v1/delivery/{$order->id}/complete", ['confirmation_code' => '123456'])->assertStatus(422);
        $this->assertDatabaseCount('wallet_transactions', 0);
    }

    public function test_agency_handover_finishes_courier_mission_without_marking_customer_delivery(): void
    {
        $order = $this->order(true);
        $this->request($order);
        $code = $order->fresh()->carrier_handover_code;
        $this->assertNotEmpty($code);
        $this->actingAs($this->admin, 'web')->get(route('admin.shipments.show', $order))->assertOk()->assertSee($code);
        $dashboard = $this->actingAs($this->courier, 'sanctum')->getJson('/api/v1/delivery/dashboard')->assertOk();
        $this->assertStringNotContainsString('carrier_handover_code', $dashboard->getContent());
        $this->postJson("/api/v1/delivery/{$order->id}/accept")->assertOk();
        $this->actingAs($this->buyer, 'sanctum')->getJson("/api/v1/orders/{$order->id}")
            ->assertOk()->assertJsonMissingPath('order.confirmation_code');
        $this->actingAs($this->courier, 'sanctum');
        foreach (['collected_by_carrier', 'partner_in_transit', 'arrived'] as $step) $this->courierStep($order, $step);
        $this->postJson("/api/v1/delivery/{$order->id}/complete", ['confirmation_code' => $code])->assertOk();
        $this->assertSame('ready_for_pickup', $order->fresh()->tracking_status);
        $this->assertSame('shipped', $order->fresh()->status);
        $this->assertNull($order->fresh()->auto_validate_at);
        $this->getJson('/api/v1/delivery/active')->assertJsonCount(0, 'deliveries');
        $this->getJson('/api/v1/delivery/dashboard')->assertJsonPath('stats.completed', 1)->assertJsonPath('stats.in_progress', 0);
        app(AssoShipmentService::class)->advance($order->fresh(), ['step' => 'collected_at_agency'], $this->admin);
        $this->assertSame('delivered', $order->fresh()->status);
    }

    public function test_existing_pending_shipment_is_recovered_and_banned_courier_cannot_advance(): void
    {
        $order = $this->order();
        $order->update(['tracking_status' => 'pickup_accepted']);
        $this->artisan('carriers:dispatch-pending')->assertSuccessful();
        $this->assertNotNull($order->fresh()->carrier_courier_requested_at);
        $this->actingAs($this->courier, 'sanctum')->postJson("/api/v1/delivery/{$order->id}/accept")->assertOk();
        DelivererCodeSync::where('user_id', $this->courier->id)->update(['is_banned' => true]);
        $this->postJson("/api/v1/delivery/{$order->id}/carrier-step", ['step' => 'collected_by_carrier'])->assertStatus(422);
        $this->assertSame('pickup_accepted', $order->fresh()->tracking_status);
    }
}
