<?php

namespace Tests\Feature;

use App\Models\DelivererCompany;
use App\Models\DeliveryAgency;
use App\Models\DeliveryRoute;
use App\Models\Order;
use App\Models\User;
use App\Services\InternationalCarrierDemoService;
use App\Support\WeightGrid;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InternationalCarrierDemoTest extends TestCase
{
    use RefreshDatabase;

    private function agency(): DeliveryAgency
    {
        $company = DelivererCompany::create(['name' => 'SOLEX', 'is_active' => true, 'service_type' => 'intercity']);

        return DeliveryAgency::create(['deliverer_company_id' => $company->id, 'code' => 'SLX-DEMO',
            'name' => 'Solex Douala', 'city' => 'Douala', 'address' => 'Akwa', 'phone' => '237670000000', 'is_active' => true]);
    }

    public function test_direct_kilo_price_uses_fractional_weight_without_rounding_up_to_a_tier(): void
    {
        $pricing = ['mode' => 'per_kg', 'price_per_kg' => 8000, 'max_weight_kg' => 100];
        $this->assertEquals(20000, WeightGrid::price($pricing, 2.5)['price']);
        $this->assertEquals(1000, WeightGrid::price($pricing, 0.125)['price']);
        $this->assertEquals(800000, WeightGrid::price($pricing, 100)['price']);
        $this->assertNull(WeightGrid::price($pricing, 100.001));
        $this->assertNull(WeightGrid::price($pricing, 0));
        $this->assertNull(WeightGrid::price($pricing, -1));
        $this->assertNull(WeightGrid::price(['mode' => 'per_kg', 'price_per_kg' => 0], 2));
        $this->assertEquals(8000, WeightGrid::describe($pricing)[0]['price']);
    }

    public function test_large_load_is_idempotent_preserves_edits_and_dry_run_writes_nothing(): void
    {
        $this->agency();
        $service = app(InternationalCarrierDemoService::class);
        $expected = count(InternationalCarrierDemoService::countries()) * 2;
        $preview = $service->load(true);
        $this->assertGreaterThan(400, $preview['created']);
        $this->assertSame($expected, $preview['created']);
        $this->assertDatabaseCount('delivery_routes', 0);
        $this->assertDatabaseCount('deliverer_companies', 1);
        $this->assertSame($expected, $service->load()['created']);
        $route = DeliveryRoute::where('destination_country', 'FR')->firstOrFail();
        $route->update(['pricing_data' => ['mode' => 'per_kg', 'price_per_kg' => 12345], 'is_active' => false]);
        $this->assertSame($expected, $service->load()['preserved']);
        $this->assertDatabaseCount('delivery_routes', $expected);
        $this->assertEquals(12345, $route->fresh()->pricing_data['price_per_kg']);
        $this->assertFalse($route->fresh()->is_active);
        foreach (['FR', 'DE', 'US', 'CA', 'SN', 'AE', 'JP', 'AU', 'BR'] as $country) {
            $routes = DeliveryRoute::with('company')->where('destination_country', $country)->get();
            $this->assertCount(2, $routes);
            foreach ($routes as $route) {
                $this->assertSame('per_kg', $route->pricing_data['mode']);
                $this->assertTrue($route->export_config['provisional_rates']);
                $this->assertSame(InternationalCarrierDemoService::DATASET, $route->export_config['demo_dataset']);
            }
        }
    }

    public function test_cleanup_only_removes_marked_routes_and_preserves_orders_and_adopted_routes(): void
    {
        $this->agency();
        $service = app(InternationalCarrierDemoService::class);
        $service->load();
        $used = DeliveryRoute::firstOrFail();
        $order = Order::create(['user_id' => User::factory()->create()->id, 'status' => 'shipped', 'payment_status' => 'paid',
            'delivery_route_id' => $used->id, 'subtotal' => 10000, 'delivery_fee' => 20000, 'total' => 30000,
            'delivery_breakdown' => ['international_export' => true, 'company_name' => 'DHL Express', 'price_per_kg' => 8000]]);
        $adopted = DeliveryRoute::where('id', '!=', $used->id)->firstOrFail();
        $config = $adopted->export_config;
        unset($config['demo_dataset']);
        $adopted->update(['export_config' => $config]);
        $before = DeliveryRoute::count();
        $service->remove(true);
        $this->assertSame($before, DeliveryRoute::count());
        $result = $service->remove();
        $this->assertSame(1, $result['deactivated']);
        $this->assertSame($before - 2, $result['deleted']);
        $this->assertDatabaseCount('delivery_routes', 2);
        $this->assertFalse($used->fresh()->is_active);
        $this->assertTrue($adopted->fresh()->is_active);
        $this->assertSame($used->id, $order->fresh()->delivery_route_id);
        $this->assertEquals(8000, $order->fresh()->delivery_breakdown['price_per_kg']);
    }

    public function test_admin_saves_direct_kilo_tariff_and_promotes_demo_route_without_losing_agency(): void
    {
        $agency = $this->agency();
        app(InternationalCarrierDemoService::class)->load();
        $route = DeliveryRoute::where('destination_country', 'FR')->firstOrFail();
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('admin.delivery-partners.edit', [$route->deliverer_company_id, 'section' => 'routes', 'edit_route' => $route->id]))
            ->assertOk()->assertSee('price_per_kg');
        $payload = ['origin_country' => 'CM', 'destination_country' => 'FR', 'pricing_mode' => 'per_kg', 'price_per_kg' => 8200,
            'solex_agency_id' => $agency->id, 'collection_price' => 2500, 'collection_max_kg' => 5,
            'coverage_mode' => 'country', 'is_active' => 1];
        $this->put(route('admin.delivery-partners.routes.update', [$route->deliverer_company_id, $route]), $payload)->assertSessionHasNoErrors();
        $this->assertEquals(20500, WeightGrid::price($route->fresh()->pricing_data, 2.5)['price']);
        $this->assertArrayNotHasKey('demo_dataset', $route->fresh()->export_config);
        $this->assertSame($agency->id, $route->fresh()->export_config['solex_agency_id']);
        $payload['price_per_kg'] = 0;
        $this->put(route('admin.delivery-partners.routes.update', [$route->deliverer_company_id, $route]), $payload)->assertSessionHasErrors('price_per_kg');
    }
}
