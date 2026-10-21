<?php

namespace Tests\Feature;

use App\Models\DelivererCompany;
use App\Models\DeliveryAgency;
use App\Models\Order;
use App\Models\User;
use App\Services\FirebaseMessagingService;
use App\Support\DeliveryPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssoManagedShipmentTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $buyer;

    private DelivererCompany $partner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(FirebaseMessagingService::class, fn ($mock) => $mock->shouldReceive('sendToUser')->andReturn([]));
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->buyer = User::factory()->create();
        $this->partner = DelivererCompany::create(['name' => 'SOLEX', 'service_type' => 'intercity', 'service_mode' => 'agency_to_agency', 'is_active' => true]);
    }

    private function order(bool $pickup = false, bool $intercity = false): Order
    {
        $route = $intercity ? \App\Models\DeliveryRoute::create([
            'deliverer_company_id' => $this->partner->id, 'origin_country' => 'CM', 'destination_country' => 'CM',
            'origin_city' => 'Douala', 'destination_city' => 'Yaoundé', 'pricing_data' => [], 'is_active' => true,
        ]) : null;

        return Order::create([
            'user_id' => $this->buyer->id, 'status' => 'confirmed', 'payment_status' => Order::PAYMENT_PAID,
            'delivery_mode' => Order::DELIVERY_CARRIER, 'delivery_company_id' => $this->partner->id,
            'delivery_route_id' => $route?->id,
            'is_wholesale' => true, 'subtotal' => 10000, 'delivery_fee' => 2000, 'total' => 12000,
            'payment_method' => 'kpay_direct', 'delivery_address' => $intercity ? 'Yaoundé' : 'Douala',
            'delivery_breakdown' => ['delivery_option' => $pickup ? 'agency_pickup' : 'home_delivery',
                'destination_city' => $intercity ? 'Yaoundé' : 'Douala'],
            'vendor_funds_status' => Order::VENDOR_FUNDS_HELD,
        ]);
    }

    private function step(Order $order, string $step, array $extra = [])
    {
        return $this->actingAs($this->admin)->post(route('admin.shipments.step', $order), $extra + [
            'step' => $step, 'confirmation_contact' => 'Contact SOLEX', 'confirmation_reference' => 'BORD-123',
            'confirmation_channel' => 'document',
        ]);
    }

    private function atHub(Order $order): void
    {
        foreach (['handed_to_carrier', 'in_transit', 'customs', 'arrived_hub'] as $step) {
            $this->step($order, $step)->assertSessionHasNoErrors();
        }
    }

    public function test_douala_home_delivery_has_separate_handover_and_customer_acknowledgement(): void
    {
        $order = $this->order();
        $this->atHub($order);
        $this->assertNull($order->fresh()->auto_validate_at);
        foreach (['pickup_requested', 'pickup_accepted', 'collected_by_carrier', 'out_for_delivery', 'delivered'] as $step) {
            $this->step($order, $step)->assertSessionHasNoErrors();
        }
        $order->refresh();
        $this->assertSame('delivered', $order->status);
        $this->assertNull($order->confirmed_by_client_at);
        $this->assertNotNull($order->auto_validate_at);
        $until = $order->auto_validate_at->toISOString();
        $this->travel(2)->hours();
        $this->actingAs($this->buyer, 'sanctum')->postJson("/api/v1/orders/{$order->id}/confirm-reception")
            ->assertOk()->assertJsonPath('order.delivery.tracking_status', 'reception_confirmed')
            ->assertJsonPath('order.delivery.can_confirm_reception', false);
        $this->assertSame($until, $order->fresh()->auto_validate_at->toISOString());
        $this->assertNotNull($order->fresh()->confirmed_by_client_at);
        $this->assertDatabaseCount('wallet_transactions', 0);
        $event = $order->trackingEvents()->where('step', 'delivered')->firstOrFail();
        $this->assertSame('BORD-123', $event->confirmation_reference);
        $this->assertSame($this->admin->id, $event->actor_id);
        $this->assertArrayNotHasKey('confirmation_reference', $event->toApi());
    }

    public function test_pickup_requires_agency_and_notifies_customer_with_its_coordinates(): void
    {
        $order = $this->order(true, true);
        $this->atHub($order);
        foreach (['pickup_requested', 'pickup_accepted', 'collected_by_carrier'] as $step) {
            $this->step($order, $step)->assertSessionHasNoErrors();
        }
        $this->step($order, 'ready_for_pickup')->assertSessionHasErrors('shipment');
        $this->step($order, 'partner_in_transit')->assertSessionHasNoErrors();
        $this->step($order, 'arrived')->assertSessionHasNoErrors();
        $this->step($order, 'ready_for_pickup')->assertSessionHasErrors('shipment');
        $agency = DeliveryAgency::create(['deliverer_company_id' => $this->partner->id, 'code' => 'SLX-YDE',
            'name' => 'SOLEX Mvan', 'city' => 'Yaoundé', 'address' => 'Gare Mvan', 'phone' => '237670000001', 'is_active' => true]);
        $this->actingAs($this->admin)->post(route('admin.shipments.agency', $order), ['delivery_agency_id' => $agency->id])->assertSessionHasNoErrors();
        $this->step($order, 'arrived')->assertSessionHasNoErrors();
        $this->mock(FirebaseMessagingService::class, function ($mock) {
            $mock->shouldReceive('sendToUser')->withArgs(fn ($user, $title, $body, $data) => $user->id === $this->buyer->id && str_contains((string) $body, 'SOLEX Mvan')
                && str_contains((string) $body, 'Gare Mvan') && str_contains((string) $body, '237670000001'))
                ->once()->andReturn([]);
        });
        $this->step($order, 'ready_for_pickup')->assertSessionHasNoErrors();
        $this->assertFalse(DeliveryPresenter::forOrder($order->fresh())['can_confirm_reception']);
        $this->assertNull($order->fresh()->auto_validate_at);
        $this->mock(FirebaseMessagingService::class, fn ($mock) => $mock->shouldReceive('sendToUser')->andReturn([]));
        $this->step($order, 'collected_at_agency')->assertSessionHasNoErrors();
        $this->assertNotNull($order->fresh()->agency_collected_at);
        $this->assertSame($this->admin->id, $order->fresh()->agency_collected_by);
        $this->assertTrue(DeliveryPresenter::forOrder($order->fresh())['can_confirm_reception']);
        $agency->update(['address' => 'Nouvelle adresse']);
        $this->assertSame('Gare Mvan', $order->fresh()->pickup_agency_snapshot['address']);
    }

    public function test_intercity_home_delivery_requires_transport_to_the_destination_before_delivery(): void
    {
        $order = $this->order(false, true);
        $this->atHub($order);
        foreach (['pickup_requested', 'pickup_accepted', 'collected_by_carrier'] as $step) {
            $this->step($order, $step)->assertSessionHasNoErrors();
        }
        $this->step($order, 'out_for_delivery')->assertSessionHasErrors('shipment');
        foreach (['partner_in_transit', 'arrived', 'out_for_delivery', 'delivered'] as $step) {
            $this->step($order, $step)->assertSessionHasNoErrors();
        }
        $this->assertSame('delivered', $order->fresh()->status);
    }

    public function test_agency_must_match_destination_and_carrier_and_legacy_availability_needs_reconfirmation(): void
    {
        $order = $this->order(true, true);
        $agency = DeliveryAgency::create(['deliverer_company_id' => $this->partner->id, 'code' => 'SLX-TEST',
            'name' => 'Solex', 'city' => 'Douala', 'address' => 'Gare', 'phone' => '237670000001', 'is_active' => true]);
        $this->actingAs($this->admin, 'web')->post(route('admin.shipments.agency', $order), ['delivery_agency_id' => $agency->id])->assertSessionHasErrors('shipment');
        $agency->update(['city' => 'Yaoundé']);
        $other = DelivererCompany::create(['name' => 'Autre partenaire', 'is_active' => true]);
        $agency->update(['deliverer_company_id' => $other->id]);
        $this->post(route('admin.shipments.agency', $order), ['delivery_agency_id' => $agency->id])->assertSessionHasErrors('shipment');
        $agency->update(['deliverer_company_id' => $this->partner->id]);
        $order->update(['status' => 'shipped', 'tracking_status' => 'ready_for_pickup']);
        $this->post(route('admin.shipments.agency', $order), ['delivery_agency_id' => $agency->id])->assertSessionHasNoErrors();
        $this->assertSame('agency_assigned', $order->fresh()->tracking_status);
        $this->step($order, 'collected_at_agency')->assertSessionHasErrors('shipment');
        $this->step($order, 'arrived')->assertSessionHasNoErrors();
        $this->post(route('admin.shipments.agency', $order), ['delivery_agency_id' => $agency->id])->assertSessionHasErrors('shipment');
        $this->step($order, 'ready_for_pickup')->assertSessionHasNoErrors();
        $agency->update(['is_active' => false]);
        $this->step($order, 'collected_at_agency')->assertSessionHasErrors('shipment');
    }

    public function test_staff_without_shipment_permission_cannot_record_a_partner_confirmation(): void
    {
        $staff = User::factory()->create(['role' => 'product_manager']);
        $order = $this->order();
        $this->actingAs($staff, 'web')->post(route('admin.shipments.step', $order), ['step' => 'handed_to_carrier'])->assertForbidden();
        $this->assertSame('confirmed', $order->fresh()->status);
    }

    public function test_skipped_steps_missing_evidence_repeated_steps_and_unpaid_balance_are_rejected(): void
    {
        $order = $this->order();
        $this->step($order, 'delivered')->assertSessionHasErrors('shipment');
        $this->atHub($order);
        $this->step($order, 'pickup_requested', ['confirmation_reference' => ''])->assertSessionHasErrors('shipment');
        $this->assertSame('arrived_hub', $order->fresh()->tracking_status);
        $this->step($order, 'pickup_requested')->assertSessionHasNoErrors();
        $count = $order->trackingEvents()->count();
        $this->step($order, 'pickup_requested')->assertSessionHasErrors('shipment');
        $this->assertSame($count, $order->trackingEvents()->count());
        foreach (['pickup_accepted', 'collected_by_carrier', 'out_for_delivery'] as $step) {
            $this->step($order, $step)->assertSessionHasNoErrors();
        }
        $order->update(['payment_plan' => Order::PLAN_DEPOSIT, 'balance_status' => Order::BALANCE_LOCKED]);
        $this->step($order, 'delivered')->assertSessionHasErrors('shipment');
        $this->assertSame('shipped', $order->fresh()->status);
        $order->update(['balance_status' => Order::BALANCE_PAID]);
        $this->step($order, 'delivered')->assertSessionHasNoErrors();
        $this->step($order, 'delivered')->assertSessionHasErrors('shipment');
    }

    public function test_no_partner_portal_or_courier_access_even_with_a_legacy_assignment(): void
    {
        $courier = User::factory()->create(['role' => 'livreur']);
        $order = $this->order();
        $order->update(['status' => 'shipped', 'tracking_status' => 'arrived_hub', 'delivery_person_id' => $courier->id]);
        $this->actingAs($courier, 'sanctum')->getJson('/api/v1/delivery/pending')->assertJsonCount(0, 'requests');
        $this->getJson('/api/v1/delivery/dashboard')->assertJsonCount(0, 'deliveries');
        $this->postJson("/api/v1/delivery/{$order->id}/accept")->assertStatus(422);
        $this->postJson("/api/v1/delivery/{$order->id}/complete", ['confirmation_code' => $order->confirmation_code])->assertNotFound();
        $this->postJson("/api/v1/orders/{$order->id}/confirm-reception")->assertNotFound();
        $this->actingAs($courier, 'web')->post(route('admin.shipments.step', $order), ['step' => 'delivered'])->assertRedirect(route('admin.login'));
        $this->get('/agency/login')->assertNotFound();
        $this->getJson('/api/v1/agency/orders')->assertNotFound();
    }

    public function test_staff_can_render_and_manage_agencies_without_creating_partner_accounts(): void
    {
        $this->actingAs($this->admin)->get(route('admin.delivery-partners.edit', $this->partner))->assertOk()->assertSee('Agences de retrait');
        $payload = ['code' => 'SLX-DLA', 'name' => 'Solex Akwa', 'city' => 'Douala', 'address' => 'Akwa', 'phone' => '237670000001'];
        $this->post(route('admin.delivery-partners.agencies.store', $this->partner), $payload)->assertSessionHasNoErrors();
        $agency = DeliveryAgency::firstOrFail();
        $this->put(route('admin.delivery-partners.agencies.update', [$this->partner, $agency]), $payload + ['is_active' => '1'])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('users', 2);
        $order = $this->order(true);
        $this->post(route('admin.shipments.agency', $order), ['delivery_agency_id' => $agency->id])->assertSessionHasNoErrors();
        $this->get(route('admin.shipments.show', $order))->assertOk()->assertSee('Solex Akwa');
    }
}
