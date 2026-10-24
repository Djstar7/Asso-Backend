<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\DelivererCompany;
use App\Models\DeliveryAgency;
use App\Models\DeliveryRoute;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Shop;
use App\Models\User;
use App\Models\WalletBalance;
use App\Services\AssoShipmentService;
use App\Services\CommissionService;
use App\Services\DeliveryQuoteService;
use App\Services\FcmService;
use App\Services\FirebaseMessagingService;
use Database\Seeders\InternationalExportSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InternationalExportTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    private DeliveryAgency $agency;

    private DeliveryRoute $route;

    protected function setUp(): void
    {
        parent::setUp();
        CommissionService::flush();
        $this->mock(FirebaseMessagingService::class, fn ($m) => $m->shouldReceive('sendToUser')->andReturn([]));
        $this->mock(FcmService::class, fn ($m) => $m->shouldIgnoreMissing());
        Setting::set('delivery_vat_rate', '19.25', 'string', 'delivery');
        Setting::set('delivery_commission_rate', '10', 'string', 'delivery');
        $seller = User::factory()->create();
        $shop = Shop::create(['user_id' => $seller->id, 'name' => 'Boutique', 'slug' => 'export-shop', 'status' => 'active', 'city' => 'Douala', 'country' => 'CM']);
        $category = Category::create(['name' => 'Mode', 'slug' => 'export-mode']);
        $this->product = Product::create(['user_id' => $seller->id, 'shop_id' => $shop->id, 'category_id' => $category->id,
            'name' => 'Sac', 'slug' => 'export-bag', 'price' => 10000, 'currency' => 'XAF', 'stock' => 20, 'weight' => '3', 'status' => 'active', 'type' => 'article']);
        $solex = DelivererCompany::create(['name' => 'SOLEX', 'service_type' => 'intercity', 'is_active' => true]);
        $this->agency = DeliveryAgency::create(['deliverer_company_id' => $solex->id, 'code' => 'SLX-DLA', 'name' => 'Solex Douala', 'city' => 'Douala', 'address' => 'Akwa', 'phone' => '237670000000', 'is_active' => true]);
        $carrier = DelivererCompany::create(['name' => 'DHL Express', 'service_type' => 'international', 'service_mode' => 'door_to_door', 'prices_exclude_vat' => true, 'is_active' => true]);
        $this->route = DeliveryRoute::create(['deliverer_company_id' => $carrier->id, 'origin_country' => 'CM', 'origin_city' => null,
            'destination_country' => 'FR', 'bidirectional' => false, 'is_active' => true,
            'pricing_data' => ['ranges' => [['max' => 5, 'price' => 30000]], 'extra_per_kg' => 6000],
            'export_config' => ['solex_agency_id' => $this->agency->id, 'coverage_mode' => 'polygon', 'zone_label' => 'Paris',
                'polygon' => [[48.8, 2.2], [48.9, 2.2], [48.9, 2.4], [48.8, 2.4]],
                'collection_pricing' => ['ranges' => [['max' => 5, 'price' => 2500]], 'extra_per_kg' => 250]],
        ]);
    }

    public function test_country_wide_coverage_is_independent_of_agency_coordinates_and_quote_availability(): void
    {
        $this->route->update(['export_config' => array_replace($this->route->export_config, ['coverage_mode' => 'country', 'polygon' => []])]);
        $this->seed(InternationalExportSeeder::class);
        $this->seed(\Database\Seeders\InternationalCarrierAgenciesSeeder::class);
        $count = DeliveryAgency::count();
        $this->seed(\Database\Seeders\InternationalCarrierAgenciesSeeder::class);
        $this->assertSame($count, DeliveryAgency::count());
        foreach ([['Paris', 48.85, 2.3], ['Bordeaux', 44.84, -0.58]] as [$city, $lat, $lng]) {
            $coverage = app(DeliveryQuoteService::class)->coverage($lat, $lng, 'FR', $city);
            $international = collect($coverage['served_by'])->where('type', 'international');
            $this->assertCount(2, $international);
            $france = collect($coverage['international_areas'])->firstWhere('id', 'FR:');
            $this->assertSame('country', $france['coverage_scope']);
            $this->assertNotEmpty($france['reference_agencies']);
            $dhl = collect($france['reference_agencies'])->firstWhere('company_name', 'DHL Express');
            $this->assertFalse($dhl['is_provisional']);
            $this->assertSame('street', $dhl['coordinate_precision']);
            $this->assertSame('https://www.dhlexpress.fr/agences-dhl', $dhl['source_url']);
        }
        $this->assertCount(498, DeliveryAgency::where('is_provisional', true)->where('is_international_reference', true)->get());
        $this->product->update(['weight' => null]);
        $this->getJson('/api/v1/delivery/coverage?'.http_build_query([
            'latitude' => 44.84, 'longitude' => -0.58, 'country' => 'FR', 'city' => 'Bordeaux',
            'items' => [['product_id' => $this->product->id, 'quantity' => 1]],
        ]))->assertOk()->assertJsonPath('coverage.geographically_served', true)
            ->assertJsonPath('coverage.quote_available', false)
            ->assertJsonCount(2, 'coverage.geographic_served_by');
    }

    private function quotes(string $country = 'FR', string $city = 'Paris', ?float $lat = 48.85, ?float $lng = 2.3): array
    {
        return app(DeliveryQuoteService::class)->quotes([['product_id' => $this->product->id, 'quantity' => 2]], $lat, $lng, $city, $country);
    }

    private function payload(): array
    {
        return ['items' => [['product_id' => $this->product->id, 'quantity' => 2]],
            'delivery_company_id' => $this->route->deliverer_company_id, 'delivery_route_id' => $this->route->id,
            'delivery_city' => 'Paris', 'delivery_country' => 'FR', 'delivery_latitude' => 48.85, 'delivery_longitude' => 2.3,
            'delivery_address' => '10 rue de Paris', 'delivery_postal_code' => '75001', 'recipient_name' => 'Destinataire Test',
            'customer_phone' => '+33600000001', 'payment_mode' => 'wallet', 'wallet_provider' => 'kpay'];
    }

    private function buyer(): User
    {
        $buyer = User::factory()->create();
        WalletBalance::create(['user_id' => $buyer->id, 'currency' => 'XAF', 'balance' => 1000000, 'locked_balance' => 0]);

        return $buyer;
    }

    public function test_quote_combines_weighted_collection_international_vat_and_commission(): void
    {
        $quote = $this->quotes();
        $this->assertSame('FR', $quote['destination']['country']);
        $this->assertSame('Paris', $quote['destination']['city']);
        $offer = $quote['partners'][0];
        $this->assertEquals(6, $quote['weight_kg']);
        $this->assertEquals(38750, $offer['breakdown']['carrier_price_ht']); // 36000 + 2750
        $this->assertEquals(7459, $offer['breakdown']['vat_amount']);
        $this->assertEquals(3875, $offer['asso_commission']);
        $this->assertEquals(50084, $offer['delivery_price']);
        $this->assertCount(2, $offer['breakdown']['legs']);
        $this->assertSame('Solex Douala', $offer['solex_agency']['name']);
        $this->assertSame('home_delivery', $offer['delivery_option']);
    }

    public function test_export_checkout_charges_exact_fractional_kilos_and_keeps_the_unit_rate(): void
    {
        $this->product->update(['weight' => '1.25']);
        $this->route->update(['pricing_data' => ['mode' => 'per_kg', 'price_per_kg' => 8000]]);
        $offer = $this->quotes()['partners'][0];
        $this->assertEquals(8000, $offer['breakdown']['price_per_kg']);
        $this->assertEquals(20000, $offer['breakdown']['legs'][1]['price']);
        $this->assertEquals(29081, $offer['delivery_price']);
        $id = $this->actingAs($this->buyer(), 'sanctum')->postJson('/api/v1/orders', $this->payload())->assertCreated()->json('order_id');
        $this->getJson('/api/v1/orders/'.$id)->assertOk()->assertJsonPath('order.delivery.breakdown.price_per_kg', 8000);
        $order = Order::findOrFail($id);
        $this->assertEquals(2.5, $order->shipping_weight_kg);
        $this->assertEquals(29081, $order->delivery_fee);
        $this->route->update(['pricing_data' => ['mode' => 'per_kg', 'price_per_kg' => 1]]);
        $this->assertEquals(8000, $order->fresh()->delivery_breakdown['price_per_kg']);
    }

    public function test_polygon_country_and_agency_are_checked_again_at_checkout(): void
    {
        $this->assertEmpty($this->quotes('FR', 'Lyon', 45.76, 4.83)['partners']);
        $this->assertEmpty($this->quotes('DE', 'Berlin', 48.85, 2.3)['partners']);
        $this->assertEmpty($this->quotes('FR', 'Paris', null, null)['partners']);
        $payload = array_replace($this->payload(), ['delivery_latitude' => 45.76, 'delivery_longitude' => 4.83]);
        $this->actingAs($this->buyer(), 'sanctum')->postJson('/api/v1/orders', $payload)->assertUnprocessable();
        $this->assertDatabaseCount('orders', 0);
        $this->assertEquals(20, $this->product->fresh()->stock);
        $this->agency->update(['is_active' => false]);
        $this->assertEmpty($this->quotes()['partners']);
    }

    public function test_exports_require_seller_weights_and_an_active_international_carrier(): void
    {
        $this->product->update(['weight' => null]);
        $this->assertSame('missing_weight', $this->quotes()['reason']);
        $this->assertNotSame('delivery.export.weight_required', $this->quotes()['message']);
        $this->assertEmpty($this->quotes()['partners']);
        $this->product->update(['weight' => '3']);
        $this->route->company->update(['is_active' => false]);
        $this->assertEmpty($this->quotes()['partners']);
    }

    public function test_both_carriers_support_france_germany_and_the_us_without_overwriting_rates(): void
    {
        $this->seed(InternationalExportSeeder::class);
        $before = $this->route->fresh()->pricing_data;
        $this->seed(InternationalExportSeeder::class);
        $this->assertSame($before, $this->route->fresh()->pricing_data);
        foreach ([['FR', 'Paris', 48.85, 2.3], ['DE', 'Berlin', 52.52, 13.4], ['US', 'New York', 40.71, -74.0]] as [$country, $city, $lat, $lng]) {
            $this->assertCount(2, $this->quotes($country, $city, $lat, $lng)['partners']);
        }
        $this->assertCount(2, $this->quotes('JP', 'Tokyo', 35.68, 139.69)['partners']);
    }

    public function test_order_snapshots_recipient_and_rates_and_requires_recipient(): void
    {
        $buyer = $this->buyer();
        $this->actingAs($buyer, 'sanctum')->postJson('/api/v1/orders', array_replace($this->payload(), ['recipient_name' => '']))->assertUnprocessable();
        $this->assertEquals(20, $this->product->fresh()->stock);
        $response = $this->postJson('/api/v1/orders', $this->payload())->assertCreated();
        $order = Order::findOrFail($response->json('order_id'));
        $this->assertTrue($order->isInternationalExport());
        $this->assertEquals(50084, $order->delivery_fee);
        $this->route->update(['pricing_data' => ['ranges' => [['max' => 10, 'price' => 1]]]]);
        $this->agency->update(['name' => 'Agence renommée']);
        $this->getJson('/api/v1/orders/'.$order->id)->assertOk()
            ->assertJsonPath('order.recipient_name', 'Destinataire Test')
            ->assertJsonPath('order.delivery_postal_code', '75001')
            ->assertJsonPath('order.delivery.solex_agency.name', 'Solex Douala');
        $this->assertEquals(50084, $order->fresh()->delivery_fee);
    }

    public function test_admin_can_find_filter_and_update_export_statuses_from_order_dashboard(): void
    {
        $buyer = $this->buyer();
        $id = $this->actingAs($buyer, 'sanctum')->postJson('/api/v1/orders', $this->payload())->assertCreated()->json('order_id');
        $order = Order::findOrFail($id);
        $this->assertFalse((bool) $order->has_local_products);
        $this->assertFalse((bool) $order->is_wholesale);
        $this->actingAs($this->product->user, 'sanctum')->postJson("/api/v1/vendor/orders/{$id}/validate")->assertOk();
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk()->assertSee('Retrouver les commandes et leur suivi');
        $this->get(route('admin.shipments.index', ['scope' => 'export']))->assertOk()
            ->assertSee($order->order_number)->assertSee('Collecte Solex programmée')->assertSee('Voir le suivi');
        $this->get(route('admin.shipments.index', ['scope' => 'export', 'search' => strtolower($order->order_number)]))->assertOk()->assertSee($order->order_number);
        $this->get(route('admin.shipments.show', $order))->assertOk()
            ->assertDontSee('aucune collecte Solex n’est encore enregistrée')
            ->assertSee('Mettre à jour le statut de livraison')->assertSee('value="export_collected"', false);
        $this->get(route('admin.shipments.index', ['scope' => 'export', 'status' => 'export_pickup_requested']))
            ->assertOk()->assertSee($order->order_number);
        $this->get(route('admin.shipments.index', ['status' => 'customs']))->assertOk()->assertDontSee($order->order_number);
        $this->get(route('admin.shipments.show', $order))->assertOk()
            ->assertSee('value="export_collected"', false)->assertDontSee('value="export_pickup_requested"', false);
        $this->assertSame('export_pickup_requested', $order->fresh()->tracking_status);
        $order->update(['status' => 'delivered', 'tracking_status' => 'delivered']);
        $this->get(route('admin.shipments.index', ['scope' => 'export']))->assertOk()->assertSee($order->order_number);
        $order->update(['status' => 'pending', 'payment_status' => 'pending', 'tracking_status' => 'pending']);
        $this->get(route('admin.shipments.index', ['scope' => 'export']))->assertOk()->assertSee($order->order_number)->assertSee('Paiement à confirmer');

    }

    public function test_export_has_ordered_tracking_with_incident_recovery_and_only_a_solex_collection_mission(): void
    {
        $buyer = $this->buyer();
        $id = $this->actingAs($buyer, 'sanctum')->postJson('/api/v1/orders', $this->payload())->assertCreated()->json('order_id');
        $order = Order::findOrFail($id);
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($this->product->user, 'sanctum')->postJson("/api/v1/vendor/orders/{$id}/validate")->assertOk();
        $this->postJson("/api/v1/vendor/orders/{$id}/hand-to-carrier", ['carrier_tracking_number' => 'BYPASS'])->assertUnprocessable();
        $service = app(AssoShipmentService::class);
        $this->assertSame(['export_collected', 'shipment_issue'], AssoShipmentService::nextSteps($order->fresh()));
        $this->actingAs($admin)->post(route('admin.shipments.step', $order), ['step' => 'delivered'])->assertSessionHasErrors();
        $this->post(route('admin.shipments.step', $order), ['step' => 'shipment_issue'])->assertSessionHasErrors();
        $order = $service->advance($order, ['step' => 'shipment_issue', 'note' => 'Vendeur absent'], $admin);
        $this->assertContains('export_collected', AssoShipmentService::nextSteps($order));
        foreach (['export_collected', 'export_at_solex'] as $step) {
            $order = $service->advance($order, ['step' => $step], $admin);
        }
        $this->post(route('admin.shipments.step', $order), ['step' => 'handed_to_carrier'])->assertSessionHasErrors();
        $order = $service->advance($order, ['step' => 'handed_to_carrier', 'carrier_tracking_number' => 'DHL-123'], $admin);
        foreach (['in_transit', 'customs', 'arrived', 'out_for_delivery', 'delivered'] as $step) {
            $order = $service->advance($order, ['step' => $step], $admin);
        }
        $this->assertSame('delivered', $order->status);
        $this->assertSame('DHL-123', $order->carrier_tracking_number);
        $this->assertNotNull($order->carrier_courier_requested_at);
        $this->assertSame($this->agency->id, $order->collection_agency_id);
        $this->assertNotNull($order->collection_completed_at);
        $this->assertCount(0, AssoShipmentService::nextSteps($order));
        $this->assertDatabaseHas('order_tracking_events', ['order_id' => $id, 'step' => 'export_at_solex', 'location' => 'Akwa']);
    }

    public function test_scheduling_export_collection_notifies_only_solex_agency_couriers_and_completes_only_the_collection(): void
    {
        $sent = [];
        $this->mock(FirebaseMessagingService::class, function ($mock) use (&$sent) {
            $mock->shouldReceive('sendToUser')->andReturnUsing(function ($user, $title, $body, $data = []) use (&$sent) {
                $sent[] = ['user_id' => $user->id, 'type' => $data['type'] ?? null];
                return [];
            });
        });
        $sync = function (?DeliveryAgency $agency, bool $online = true, bool $banned = false, ?int $company = null) {
            $courier = User::factory()->create(['role' => 'livreur']);
            $code = \App\Models\DelivererSyncCode::create(['company_id' => $company ?? $this->agency->deliverer_company_id,
                'delivery_agency_id' => $agency?->id, 'sync_code' => \App\Models\DelivererSyncCode::generateSyncCode(),
                'expires_at' => now()->addDay()]);
            \App\Models\DelivererCodeSync::create(['sync_code_id' => $code->id, 'company_id' => $code->company_id,
                'delivery_agency_id' => $agency?->id, 'user_id' => $courier->id,
                'is_active' => true, 'is_online' => $online, 'is_banned' => $banned]);
            return $courier;
        };
        $courier = $sync($this->agency);
        $second = $sync($this->agency);
        $offline = $sync($this->agency, false);
        $banned = $sync($this->agency, true, true);
        $unassigned = $sync(null);
        $foreign = $sync(null, company: $this->route->deliverer_company_id);
        $otherAgency = DeliveryAgency::create(['deliverer_company_id' => $this->agency->deliverer_company_id,
            'code' => 'SLX-BFS-OTHER', 'name' => 'Solex Bafoussam', 'city' => 'Bafoussam', 'address' => 'Centre', 'phone' => '237670000002', 'is_active' => true]);
        $other = $sync($otherAgency);
        $buyer = $this->buyer();
        $id = $this->actingAs($buyer, 'sanctum')->postJson('/api/v1/orders', $this->payload())->assertCreated()->json('order_id');
        $this->actingAs($this->product->user, 'sanctum')->postJson("/api/v1/vendor/orders/{$id}/validate")->assertOk();
        $order = Order::findOrFail($id);
        $this->assertSame('export_pickup_requested', $order->tracking_status);
        $this->assertDatabaseHas('order_tracking_events', ['order_id' => $id,
            'step' => 'export_pickup_requested', 'actor_type' => 'system', 'actor_id' => null]);
        $this->actingAs($this->product->user, 'sanctum')->postJson("/api/v1/vendor/orders/{$id}/validate")->assertUnprocessable();
        $this->assertSame(1, $order->trackingEvents()->where('step', 'export_pickup_requested')->count());
        app(\App\Services\CarrierCourierService::class)->request($order->fresh());
        $recipients = collect($sent)->where('type', 'new_delivery_request')->pluck('user_id')->all();
        $this->assertEqualsCanonicalizing([$courier->id, $second->id, $offline->id], $recipients);
        foreach ([$banned, $unassigned, $foreign, $other] as $excluded) {
            $this->actingAs($excluded, 'sanctum')->getJson('/api/v1/delivery/pending')->assertJsonCount(0, 'requests');
        }
        $this->actingAs($offline, 'sanctum')->getJson('/api/v1/delivery/pending')->assertJsonCount(0, 'requests');
        $this->actingAs($courier, 'sanctum')->getJson('/api/v1/delivery/pending')->assertOk()
            ->assertJsonCount(1, 'requests')->assertJsonPath('requests.0.dropoff.address', 'Akwa')
            ->assertJsonPath('requests.0.delivery_address', 'Akwa')->assertJsonPath('requests.0.collection_only', true)
            ->assertJsonPath('requests.0.commission', 2750);
        $this->getJson('/api/v1/delivery/dashboard')->assertJsonPath('stats.pending', 1);
        $this->actingAs($other, 'sanctum')->postJson("/api/v1/delivery/{$id}/accept")->assertUnprocessable();
        $this->actingAs($courier, 'sanctum')->postJson("/api/v1/delivery/{$id}/accept")->assertOk();
        $this->actingAs($second, 'sanctum')->postJson("/api/v1/delivery/{$id}/accept")->assertUnprocessable();
        $order->refresh();
        $this->assertSame($courier->id, $order->delivery_person_id);
        $this->assertSame($this->route->deliverer_company_id, $order->delivery_company_id);
        $before = \App\Models\WalletTransaction::count();
        $this->actingAs($courier, 'sanctum')->postJson("/api/v1/delivery/{$id}/carrier-step", ['step' => 'export_collected'])->assertOk();
        $this->postJson("/api/v1/delivery/{$id}/complete", ['confirmation_code' => 'WRONG'])->assertUnprocessable();
        $this->postJson("/api/v1/delivery/{$id}/complete", ['confirmation_code' => $order->carrier_handover_code])->assertOk();
        $order->refresh();
        $this->assertSame('shipped', $order->status);
        $this->assertSame('export_at_solex', $order->tracking_status);
        $this->assertNotNull($order->collection_completed_at);
        $this->assertNull($order->delivered_at);
        $this->assertNull($order->carrier_handover_code);
        $this->assertSame($before, \App\Models\WalletTransaction::count());
        $this->getJson('/api/v1/delivery/active')->assertJsonCount(0, 'deliveries');
        $this->getJson('/api/v1/delivery/dashboard')->assertJsonPath('stats.completed', 1)->assertJsonPath('stats.in_progress', 0);
        $this->postJson("/api/v1/delivery/{$id}/carrier-step", ['step' => 'in_transit'])->assertUnprocessable();
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->get(route('admin.deliverers.syncs.company', $this->agency->deliverer_company_id))
            ->assertOk()->assertSee('Enregistrer l’agence');
        $this->actingAs($buyer, 'sanctum')->getJson("/api/v1/orders/{$id}")->assertOk()
            ->assertJsonMissingPath('order.confirmation_code')->assertJsonMissingPath('order.delivery_person')
            ->assertJsonPath('order.collection_courier.id', $courier->id);
        foreach (['handed_to_carrier', 'in_transit', 'arrived', 'out_for_delivery'] as $step) {
            $order = app(AssoShipmentService::class)->advance($order,
                ['step' => $step, 'carrier_tracking_number' => 'DHL-REAL-123'], $admin);
        }
        $this->getJson("/api/v1/orders/{$id}")->assertOk()
            ->assertJsonMissingPath('order.confirmation_code')->assertJsonMissingPath('order.delivery_person')
            ->assertJsonPath('order.delivery.carrier_tracking_number', 'DHL-REAL-123')
            ->assertJsonPath('order.delivery.tracking_status_label', __('delivery.export.carrier_out_for_delivery', ['carrier' => 'DHL Express']));
        $this->assertCount(3, collect($sent)->where('type', 'new_delivery_request'));
        $this->actingAs($admin)->get(route('admin.shipments.show', $order))->assertOk()
            ->assertSee('Collecte Solex terminée')->assertDontSee('Mission de collecte ouverte');
    }

    public function test_unpaid_export_cannot_schedule_collection_and_system_cannot_confirm_physical_pickup(): void
    {
        $id = $this->actingAs($this->buyer(), 'sanctum')->postJson('/api/v1/orders', $this->payload())
            ->assertCreated()->json('order_id');
        $order = Order::findOrFail($id);
        $order->update(['payment_status' => 'pending']);
        $seller = $this->product->user;
        $this->actingAs($seller, 'sanctum')->postJson("/api/v1/vendor/orders/{$id}/validate")->assertUnprocessable();
        $this->assertNull($order->fresh()->carrier_courier_requested_at);
        $this->assertSame(0, $order->trackingEvents()->where('step', 'export_pickup_requested')->count());
        $order->update(['payment_status' => Order::PAYMENT_PAID]);
        $this->postJson("/api/v1/vendor/orders/{$id}/validate")->assertOk();
        $this->assertSame('export_pickup_requested', $order->fresh()->tracking_status);
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(AssoShipmentService::class)->advance($order, ['step' => 'export_collected'], $seller, 'system');
    }

    public function test_test_tracking_is_automatic_stable_internal_and_disabled_by_default_for_both_carriers(): void
    {
        $this->assertFalse(\App\Support\ExportTestTracking::enabled());
        $admin = User::factory()->create(['role' => 'admin']);
        foreach (['DHL Express', 'FedEx'] as $name) {
            config(['shipping.export_test_tracking' => false]);
            $this->route->company->update(['name' => $name,
                'tracking_url_template' => 'https://example.com/track/{number}']);
            $id = $this->actingAs($this->buyer(), 'sanctum')->postJson('/api/v1/orders', $this->payload())
                ->assertCreated()->json('order_id');
            $this->actingAs($this->product->user, 'sanctum')->postJson("/api/v1/vendor/orders/{$id}/validate")->assertOk();
            $order = Order::findOrFail($id);
            foreach (['export_collected', 'export_at_solex'] as $step) {
                $order = app(AssoShipmentService::class)->advance($order, ['step' => $step], $admin);
            }
            $fee = $order->delivery_fee;
            $this->actingAs($admin)->post(route('admin.shipments.step', $order), ['step' => 'handed_to_carrier'])
                ->assertSessionHasErrors();
            $this->post(route('admin.shipments.step', $order), ['step' => 'handed_to_carrier',
                'carrier_tracking_number' => 'TEST-ASSO-MANUAL'])->assertSessionHasErrors();
            $this->assertSame('export_at_solex', $order->fresh()->tracking_status);
            config(['shipping.export_test_tracking' => true]);
            $reference = \App\Support\ExportTestTracking::numberFor($order);
            $this->assertStringStartsWith('TEST-ASSO-', $reference);
            $this->get(route('admin.shipments.show', $order))->assertOk()->assertSee($reference)
                ->assertSee('Mode test');
            $this->assertNull($order->fresh()->carrier_tracking_number); // Rendering does not record a handover.
            $this->assertSame($reference, \App\Support\ExportTestTracking::numberFor($order->fresh()));
            $this->post(route('admin.shipments.step', $order), ['step' => 'handed_to_carrier'])
                ->assertSessionHasNoErrors();
            $order->refresh()->load('deliveryCompany');
            $this->assertSame('handed_to_carrier', $order->tracking_status);
            $this->assertSame($reference, $order->carrier_tracking_number);
            $this->assertEquals($fee, $order->delivery_fee);
            $this->assertNull(\App\Support\DeliveryPresenter::forOrder($order)['carrier_tracking_url']);
            config(['shipping.export_test_tracking' => false]);
            $this->assertNull(\App\Support\DeliveryPresenter::forOrder($order)['carrier_tracking_url']);
            $order->update(['carrier_tracking_number' => 'REAL123456']);
            config(['shipping.export_test_tracking' => true]);
            $this->assertSame('REAL123456', \App\Support\ExportTestTracking::numberFor($order));
            $this->assertSame('https://example.com/track/REAL123456', $order->deliveryCompany->trackingUrl($order->carrier_tracking_number));
        }
    }

    public function test_sync_codes_and_existing_couriers_can_be_bound_only_to_their_own_agency(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $solex = $this->agency->company;
        $this->actingAs($admin)->post(route('admin.deliverers.sync-code', $solex), ['delivery_agency_id' => $this->agency->id])
            ->assertRedirect()->assertSessionHasNoErrors();
        $code = $solex->syncCodes()->latest('id')->firstOrFail();
        $this->assertSame($this->agency->id, $code->delivery_agency_id);
        $courier = User::factory()->create(['role' => 'livreur']);
        $this->actingAs($courier, 'sanctum')->postJson('/api/v1/deliverer/sync-profile', ['sync_code' => $code->sync_code])->assertOk();
        $sync = \App\Models\DelivererCodeSync::where('user_id', $courier->id)->firstOrFail();
        $this->assertSame($this->agency->id, $sync->delivery_agency_id);
        $this->actingAs($admin)->post(route('admin.deliverers.syncs.agency', $sync), ['delivery_agency_id' => null])->assertSessionHasNoErrors();
        $this->assertNull($sync->fresh()->delivery_agency_id);
        $this->post(route('admin.deliverers.syncs.agency', $sync), ['delivery_agency_id' => $this->agency->id])->assertSessionHasNoErrors();
        $this->assertSame($this->agency->id, $sync->fresh()->delivery_agency_id);
        $foreign = DeliveryAgency::create(['deliverer_company_id' => $this->route->deliverer_company_id,
            'code' => 'DHL-REF-INVALID', 'name' => 'DHL', 'city' => 'Paris', 'address' => 'Paris', 'phone' => '330000000', 'is_active' => true]);
        $this->post(route('admin.deliverers.syncs.agency', $sync), ['delivery_agency_id' => $foreign->id])->assertSessionHasErrors('delivery_agency_id');
        $this->assertSame($this->agency->id, $sync->fresh()->delivery_agency_id);
        $this->post(route('admin.deliverers.sync-code', $solex), ['delivery_agency_id' => $foreign->id])->assertSessionHasErrors('delivery_agency_id');
        $this->assertSame(1, $solex->syncCodes()->count());
        $this->get(route('admin.delivery-partners.edit', ['partner' => $solex, 'section' => 'settings']))->assertOk()->assertSee('Agence du code');
    }

    public function test_collection_distance_and_price_are_separate_and_frozen_at_checkout(): void
    {
        $this->product->shop->update(['latitude' => 4.09, 'longitude' => 9.79]);
        $this->agency->update(['latitude' => 4.05, 'longitude' => 9.70, 'coordinate_precision' => 'precise']);
        $config = $this->route->export_config;
        $config['collection_pricing']['price_per_km'] = 100;
        $this->route->update(['export_config' => $config]);
        $km = round(\App\Models\DeliveryCityGrid::distanceKm(4.09, 9.79, 4.05, 9.70), 3);
        $collection = 2750 + round($km * 100);
        $offer = $this->quotes()['partners'][0];
        $leg = $offer['breakdown']['legs'][0];
        $this->assertSame('export_collection', $leg['kind']);
        $this->assertEquals($km, $leg['distance_km']);
        $this->assertEquals(100, $leg['price_per_km']);
        $this->assertEquals($collection, $leg['price']);
        $this->assertEquals(36000, $offer['breakdown']['legs'][1]['price']);
        $this->assertEquals(36000 + $collection, $offer['breakdown']['carrier_price_ht']);
        $id = $this->actingAs($this->buyer(), 'sanctum')->postJson('/api/v1/orders', $this->payload())->assertCreated()->json('order_id');
        $order = Order::findOrFail($id);
        $before = $order->delivery_fee;
        $this->agency->update(['latitude' => 6, 'longitude' => 10]);
        $this->product->shop->update(['latitude' => 8, 'longitude' => 11]);
        $mission = \App\Services\CarrierCourierService::presentation($order);
        $this->assertEquals($collection, $mission['commission']);
        $this->assertEquals($km, $mission['distance']);
        $this->assertSame('straight_line', $mission['distance_type']);
        $this->assertEquals(4.05, \App\Services\CarrierCourierService::dropoff($order)['latitude']);
        $this->assertEquals(4.09, \App\Support\DeliveryPresenter::pickupFor($order)['latitude']);
        $this->assertEquals($before, $order->fresh()->delivery_fee);
        $this->product->shop->update(['latitude' => null, 'longitude' => null]);
        $this->assertFalse($this->quotes()['available']); // Never bill distance with missing coordinates.
    }

    public function test_admin_kg_input_and_central_solex_price_produce_an_additive_export_quote(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->put(route('admin.products.update', $this->product), [
            'shop_id' => $this->product->shop_id, 'category_id' => $this->product->category_id,
            'name' => $this->product->name, 'price_type' => 'fixed', 'price' => 10000,
            'type' => 'article', 'origin_country' => 'imported', 'weight' => '1', 'stock' => 20, 'status' => 'active',
        ])->assertSessionHasNoErrors();
        $this->assertEquals(1, $this->product->fresh()->weightKg());
        $solex = $this->agency->company;
        $this->get(route('admin.delivery-partners.edit', [$solex, 'section' => 'collection']))->assertOk()
            ->assertSee('Tarif boutique du vendeur')->assertSee('Enregistrer le tarif de collecte');
        $this->post(route('admin.delivery-partners.agencies.collection-pricing', [$solex, $this->agency]), [
            'collection_price' => 4200, 'collection_max_kg' => 5, 'collection_extra_per_kg' => 300, 'collection_price_per_km' => 0,
        ])->assertSessionHasNoErrors();
        $this->route->update(['pricing_data' => ['mode' => 'per_kg', 'price_per_kg' => 8000, 'max_weight_kg' => 100]]);
        $quote = $this->quotes();
        $this->assertEquals(2, $quote['weight_kg']);
        $price = $quote['partners'][0]['breakdown'];
        $this->assertEquals(4200, $price['legs'][0]['price']);
        $this->assertEquals(16000, $price['legs'][1]['price']);
        $this->assertEquals(20200, $price['carrier_price_ht']);
        $this->assertEquals(array_sum(array_column($price['legs'], 'price')) + $price['vat_amount'] + $price['asso_commission'], $price['total']);
        $this->seed(\Database\Seeders\SolexCollectionPricingSeeder::class);
        $this->assertEquals(4200, $this->agency->fresh()->collection_pricing['ranges'][0]['price']);
        $this->post(route('admin.delivery-partners.agencies.collection-pricing', [$solex, $this->agency]), [
            'collection_price' => -1, 'collection_max_kg' => 5, 'collection_extra_per_kg' => 300, 'collection_price_per_km' => 0,
        ])->assertSessionHasErrors('collection_price');
        $this->assertEquals(4200, $this->agency->fresh()->collection_pricing['ranges'][0]['price']);
    }

    public function test_diaspora_card_checkout_uses_stripe_and_waits_for_confirmed_payment(): void
    {
        \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::response([], 503)]);
        \App\Models\ServiceConfiguration::updateOrCreate(['service_name' => 'stripe'], [
            'service_type' => 'payment', 'is_active' => true,
            'configuration' => ['secret_key' => 'sk_test_export', 'publishable_key' => 'pk_test_export'],
        ]);
        Setting::set('stripe_enabled', '1', 'boolean', 'payments');
        Setting::set('stripe_currency', 'EUR', 'string', 'payments');
        $this->mock(\App\Services\StripeService::class, function ($mock) {
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('createPaymentIntent')->once()
                ->withArgs(fn ($amount, $currency, $metadata) => $amount > 0 && $currency === 'EUR' && $metadata['asso_kind'] === 'order')
                ->andReturn(['id' => 'pi_export', 'client_secret' => 'pi_export_secret', 'publishable_key' => 'pk_test_export']);
        });
        $payload = array_replace($this->payload(), ['payment_mode' => 'stripe_direct']);
        $response = $this->actingAs(User::factory()->create(), 'sanctum')->postJson('/api/v1/orders', $payload)
            ->assertCreated()->assertJsonPath('payment_intent_id', 'pi_export')->assertJsonPath('client_secret', 'pi_export_secret');
        $order = Order::findOrFail($response->json('order_id'));
        $this->assertSame('pending', $order->payment_status);
        $this->assertSame('EUR', $order->payment_currency);
        $this->assertSame([], AssoShipmentService::nextSteps($order));
    }

    public function test_checkout_map_shows_export_areas_and_prices_overseas_without_assuming_cameroon(): void
    {
        $params = ['latitude' => 48.85, 'longitude' => 2.3, 'items' => $this->payload()['items']];
        $map = $this->getJson('/api/v1/delivery/coverage?'.http_build_query($params))->assertOk()
            ->assertJsonPath('coverage.country', 'FR')->assertJsonPath('coverage.served', true)
            ->assertJsonPath('coverage.quote_available', true)
            ->assertJsonPath('coverage.served_by.0.company_name', 'DHL Express')->json('coverage');
        $this->assertCount(1, $map['international_areas']);
        $this->assertSame(['DHL Express'], $map['international_areas'][0]['companies']);
        $this->assertNotEmpty($map['international_areas'][0]['polygons']);
        $this->route->update(['is_active' => false]);
        $this->getJson('/api/v1/delivery/coverage?'.http_build_query($params))->assertOk()
            ->assertJsonPath('coverage.served', false)->assertJsonCount(0, 'coverage.international_areas');
    }

    public function test_world_map_groups_both_carriers_and_preserves_all_demo_destinations(): void
    {
        $this->seed(InternationalExportSeeder::class);
        $map = $this->getJson('/api/v1/delivery/coverage?latitude=52.52&longitude=13.4')->assertOk()
            ->assertJsonPath('coverage.country', 'DE')->assertJsonPath('coverage.served', true)->json('coverage');
        $this->assertCount(249, collect($map['international_areas'])->pluck('country')->unique());
        $germany = collect($map['international_areas'])->firstWhere('country', 'DE');
        $this->assertEqualsCanonicalizing(['DHL Express', 'FedEx'], $germany['companies']);
        $this->assertNotEmpty($germany['polygons']);
        $this->assertTrue($germany['provisional_rates']);
        $this->assertSame('US', \App\Support\DeliveryCountryBoundaries::countryAt(40.71, -74.0));
        $this->assertSame('CM', \App\Support\DeliveryCountryBoundaries::countryAt(5.47, 10.42));
        $this->assertSame('LS', \App\Support\DeliveryCountryBoundaries::countryAt(-29.31, 27.48));
        $this->assertNull(\App\Support\DeliveryCountryBoundaries::countryAt(0, 0));
    }

    public function test_admin_can_configure_foreign_destination_and_draw_coverage(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $payload = ['origin_country' => 'CM', 'destination_country' => 'DE', 'bidirectional' => '1', 'is_active' => '1',
            'ranges' => [['min' => 0, 'max' => 5, 'price' => 30000]], 'solex_agency_id' => $this->agency->id,
            'collection_price' => 2500, 'collection_max_kg' => 5, 'collection_price_per_km' => 125, 'coverage_mode' => 'polygon',
            'coverage_polygon' => json_encode([[52, 13], [53, 13], [53, 14], [52, 14]])];
        $this->actingAs($admin)->post(route('admin.delivery-partners.routes.store', $this->route->deliverer_company_id), $payload)->assertSessionHasNoErrors();
        $route = DeliveryRoute::where('destination_country', 'DE')->firstOrFail();
        $this->assertFalse($route->bidirectional);
        $this->assertEquals(125, $route->export_config['collection_pricing']['price_per_km']);
        $this->get(route('admin.delivery-partners.edit', [$route->deliverer_company_id, 'section' => 'routes', 'edit_route' => $route->id]))
            ->assertOk()->assertSee('export-coverage-map')->assertSee('Solex Douala');
        $payload['coverage_polygon'] = '[[1,2],[2,3],[3,4]]';
        $this->post(route('admin.delivery-partners.routes.store', $this->route->deliverer_company_id), $payload)->assertSessionHasErrors('coverage_polygon');
    }
}
