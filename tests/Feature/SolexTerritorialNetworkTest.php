<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\DelivererCompany;
use App\Models\DeliveryAgency;
use App\Models\DeliveryCityGrid;
use App\Models\DeliveryRoute;
use App\Models\DeliveryTerritory;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Shop;
use App\Models\User;
use App\Services\DeliveryQuoteService;
use Database\Seeders\SolexNetworkSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SolexTerritorialNetworkTest extends TestCase
{
    use RefreshDatabase;

    private function prepare(): array
    {
        (new SolexNetworkSeeder)->run();
        // Isolate the two departments and useful routes for price assertions.
        DeliveryCityGrid::whereNotNull('department_code')->update(['is_active' => false]);
        DeliveryRoute::whereNotNull('network_key')->update(['is_active' => false]);
        $company = DelivererCompany::where('name', 'SOLEX')->firstOrFail();
        $company->update(['territorial_network_enabled' => true]);
        Setting::set('delivery_commission_rate', 10, 'string');
        Setting::set('delivery_vat_rate', 19.25, 'string');
        $origin = $this->activate('Wouri', 1000);
        $destination = $this->activate('Mfoundi', 2000);
        $route = DeliveryRoute::where('network_level', 'interregional')
            ->whereIn('origin_territory_code', ['CM002', 'CM005'])
            ->whereIn('destination_territory_code', ['CM002', 'CM005'])->firstOrFail();
        $route->update(['is_active' => true, 'pricing_data' => ['ranges' => [['min' => 0, 'max' => 100, 'price' => 3000]]]]);
        $seller = User::factory()->create();
        $zone = $origin->zone($origin->agency_zone);
        $shop = Shop::create(['user_id' => $seller->id, 'name' => 'Boutique', 'slug' => 'solex-boutique',
            'status' => 'active', 'city' => $origin->city, 'country' => 'Cameroun',
            'latitude' => $zone['center_latitude'], 'longitude' => $zone['center_longitude']]);
        $product = Product::create(['user_id' => $seller->id, 'shop_id' => $shop->id,
            'category_id' => Category::create(['name' => 'Mode', 'slug' => 'mode'])->id,
            'name' => 'Sac', 'slug' => 'sac', 'price' => 10000, 'currency' => 'XAF',
            'stock' => 20, 'status' => 'active', 'type' => 'article', 'weight' => '1']);

        return [$company, $origin, $destination, $route, $product];
    }

    private function activate(string $department, int $price): DeliveryCityGrid
    {
        $territory = DeliveryTerritory::where('name', $department)->where('level', 2)->firstOrFail();
        $grid = DeliveryCityGrid::where('department_code', $territory->code)->firstOrFail();
        $zones = collect($grid->zones)->map(fn ($z) => array_replace($z, ['is_active' => $z['code'] === $grid->agency_zone, 'radius_km' => 5]))->all();
        $vehicle = $grid->vehicles[0];
        $vehicle['prices'] = array_map(fn () => $price, $vehicle['prices']);
        $grid->update(['is_active' => true, 'zones' => $zones, 'vehicles' => [$vehicle]]);
        DeliveryAgency::where('code', 'SOLEX-'.$territory->code)->update(['is_active' => true, 'address' => 'Rue du marché', 'phone' => '677000000']);

        return $grid->fresh();
    }

    private function quotes(Product $product, DeliveryCityGrid $destination, ?array $coordinates = null): array
    {
        $point = $coordinates ?? $destination->zone($destination->agency_zone);

        return app(DeliveryQuoteService::class)->quotes([['product_id' => $product->id, 'quantity' => 1]],
            $point['center_latitude'], $point['center_longitude'], $destination->city, 'CM', 'FAUX QUARTIER');
    }

    public function test_seed_is_complete_active_by_default_and_preserves_manual_settings(): void
    {
        $this->artisan('solex:sync', ['--dry-run' => true])->assertSuccessful();
        $this->assertDatabaseCount('delivery_territories', 0);
        $company = DelivererCompany::create(['name' => 'SOLEX', 'is_active' => true]);
        $legacy = DeliveryCityGrid::create(['deliverer_company_id' => $company->id, 'city' => 'Douala',
            'country' => 'CM', 'zones' => [['code' => 1, 'label' => 'Bali', 'quarters' => []]],
            'vehicles' => [], 'is_active' => true]);
        (new SolexNetworkSeeder)->run();
        $this->assertTrue($legacy->fresh()->is_active);
        $this->assertNull($legacy->fresh()->department_code);
        $this->assertDatabaseCount('delivery_territories', 428);
        $this->assertSame(45, DeliveryRoute::where('network_level', 'interregional')->count());
        $this->assertSame(158, DeliveryRoute::where('network_level', 'intraregional')->count());
        $this->assertDatabaseCount('delivery_city_grids', 59);
        $this->assertSame(360, DeliveryCityGrid::whereNotNull('department_code')->get()->sum(fn ($g) => count($g->zones)));
        $this->assertSame(58, DeliveryCityGrid::whereNotNull('department_code')->where('is_active', true)->count());
        $this->assertSame(203, DeliveryRoute::where('is_active', true)->count());
        $this->assertTrue(DelivererCompany::first()->territorial_network_enabled);
        $this->assertSame(360, DeliveryCityGrid::whereNotNull('department_code')->get()->sum(fn ($g) => collect($g->zones)->where('is_active', true)->count()));
        $this->assertSame(58, DeliveryAgency::where('is_active', true)->count());
        $grid = DeliveryCityGrid::whereNotNull('department_code')->first();
        $zones = $grid->zones;
        $zones[0]['radius_km'] = 23;
        $grid->update(['zones' => $zones, 'is_active' => false]);
        $route = DeliveryRoute::first();
        $route->update(['pricing_data' => ['ranges' => [['min' => 0, 'max' => 10, 'price' => 12345]]], 'is_active' => false]);
        (new SolexNetworkSeeder)->run();
        $this->assertDatabaseCount('delivery_territories', 428);
        $this->assertDatabaseCount('delivery_routes', 203);
        $this->assertEquals(23, $grid->fresh()->zones[0]['radius_km']);
        $this->assertSame(12345, $route->fresh()->pricing_data['ranges'][0]['price']);
        $this->assertTrue($grid->fresh()->is_active);
        $this->assertTrue($route->fresh()->is_active);
    }

    public function test_sync_reactivates_existing_network_without_changing_prices_or_radii(): void
    {
        (new SolexNetworkSeeder)->run();
        $company = DelivererCompany::where('name', 'SOLEX')->firstOrFail();
        $company->update(['territorial_network_enabled' => false]);
        $grid = DeliveryCityGrid::whereNotNull('department_code')->firstOrFail();
        $zones = collect($grid->zones)->map(fn ($zone) => array_replace($zone, ['is_active' => false, 'radius_km' => 23]))->all();
        $grid->update(['is_active' => false, 'zones' => $zones]);
        $route = DeliveryRoute::whereNotNull('network_key')->firstOrFail();
        $prices = ['ranges' => [['min' => 0, 'max' => 10, 'price' => 12345]]];
        $route->update(['is_active' => false, 'pricing_data' => $prices]);
        $this->artisan('solex:sync', ['--activate' => true, '--dry-run' => true])->assertSuccessful();
        $this->assertFalse($grid->fresh()->is_active);
        $this->assertFalse($company->fresh()->territorial_network_enabled);
        $this->artisan('solex:sync')->assertSuccessful();
        $this->assertTrue($company->fresh()->territorial_network_enabled);
        $this->assertSame(58, DeliveryCityGrid::whereNotNull('department_code')->where('is_active', true)->count());
        $this->assertSame(360, DeliveryCityGrid::whereNotNull('department_code')->get()->sum(fn ($g) => collect($g->zones)->where('is_active', true)->count()));
        $this->assertSame(203, DeliveryRoute::whereNotNull('network_key')->where('is_active', true)->count());
        $this->assertSame(23, $grid->fresh()->zones[0]['radius_km']);
        $this->assertEquals($prices, $route->fresh()->pricing_data);
        $this->assertSame(58, DeliveryAgency::where('is_active', true)->count());
    }

    public function test_admin_landing_shows_complete_geography_without_bulk_editors_and_routes_are_paginated(): void
    {
        (new SolexNetworkSeeder)->run();
        $company = DelivererCompany::where('name', 'SOLEX')->firstOrFail();
        $admin = User::factory()->create(['role' => 'admin']);
        $page = $this->actingAs($admin)->get(route('admin.delivery-partners.edit', $company))->assertOk();
        $html = $page->getContent();
        $this->assertSame(10, substr_count($html, 'data-network-region='));
        $this->assertSame(58, substr_count($html, 'data-network-department='));
        $this->assertSame(360, substr_count($html, 'data-network-zone='));
        $page->assertDontSee('Anciennes grilles par ville')->assertDontSee('max-w-screen-2xl');
        $page->assertDontSee('territorial_form_')->assertDontSee('leaflet.js')->assertDontSee('chart.js');
        $this->assertLessThan(500000, strlen($html), 'The landing page must not embed all edit forms.');

        $routes = $this->get(route('admin.delivery-partners.edit', [$company, 'section' => 'routes', 'network_level' => 'interregional']))->assertOk();
        $this->assertSame(45, $routes->viewData('routesPage')->total());
        $this->assertCount(15, $routes->viewData('routesPage')->items());
        $routes->assertDontSee('name="origin_country"', false);
        $route = DeliveryRoute::where('network_level', 'interregional')->firstOrFail();
        $this->get(route('admin.delivery-partners.edit', [$company, 'section' => 'routes', 'edit_route' => $route->id]))
            ->assertOk()->assertSee('name="origin_country"', false);
        $foreignPartner = DelivererCompany::create(['name' => 'Autre transporteur']);
        $this->get(route('admin.delivery-partners.edit', [$foreignPartner, 'section' => 'routes', 'edit_route' => $route->id]))->assertNotFound();
        $agencies = $this->get(route('admin.delivery-partners.edit', [$company, 'section' => 'agencies']))->assertOk();
        $this->assertSame(58, $agencies->viewData('agencies')->total());
        $this->assertCount(10, $agencies->viewData('agencies')->items());
    }

    public function test_admin_overview_exposes_all_regions_departments_zones_and_complete_connections(): void
    {
        (new SolexNetworkSeeder)->run();
        $partner = DelivererCompany::where('name', 'SOLEX')->with(['cityGrids', 'deliveryRoutes'])->firstOrFail();
        $territories = DeliveryTerritory::all()->keyBy('code');
        $networkGrids = $partner->cityGrids->whereNotNull('department_code');
        $html = view('admin.delivery_partners._network_overview', compact('partner', 'territories', 'networkGrids'))->render();

        $this->assertSame(10, substr_count($html, 'data-network-region='));
        $this->assertSame(58, substr_count($html, 'data-network-department='));
        $this->assertSame(360, substr_count($html, 'data-network-zone='));
        $this->assertStringContainsString('Rayon : 10 km', $html);
        $this->assertStringContainsString('Actif', $html);

        foreach ($territories->where('level', 1) as $region) {
            $links = $partner->deliveryRoutes->where('network_level', 'interregional')
                ->filter(fn ($route) => $route->origin_territory_code === $region->code || $route->destination_territory_code === $region->code);
            $neighbors = $links->map(fn ($route) => $route->origin_territory_code === $region->code ? $route->destination_territory_code : $route->origin_territory_code);
            $this->assertEqualsCanonicalizing($territories->where('level', 1)->where('code', '!=', $region->code)->pluck('code')->all(), $neighbors->all());
            $this->assertTrue($links->every(fn ($route) => $route->bidirectional));

            $departmentCodes = $territories->where('level', 2)->where('parent_code', $region->code)->pluck('code');
            $localLinks = $partner->deliveryRoutes->where('network_level', 'intraregional')
                ->whereIn('origin_territory_code', $departmentCodes)->whereIn('destination_territory_code', $departmentCodes);
            $this->assertSame((int) ($departmentCodes->count() * ($departmentCodes->count() - 1) / 2), $localLinks->count());
            $this->assertTrue($localLinks->every(fn ($route) => $route->bidirectional));
        }
    }

    public function test_radius_boundary_and_disabled_zone_use_coordinates_without_quarter_names(): void
    {
        [, $origin] = $this->prepare();
        $point = $origin->zone($origin->agency_zone);
        $lat = $point['center_latitude'];
        $lng = $point['center_longitude'];
        $this->assertNotEmpty($origin->circularMatches($lat + rad2deg(5 / 6371), $lng));
        $this->assertSame([], $origin->circularMatches($lat + rad2deg(5.0001 / 6371), $lng));
        $this->assertSame([], $origin->circularMatches(null, $lng));
        $zones = $origin->zones;
        foreach ($zones as &$z) {
            $z['is_active'] = false;
        }
        $origin->update(['zones' => $zones]);
        $this->assertSame([], $origin->circularMatches($lat, $lng));
    }

    public function test_both_options_include_shop_collection_and_one_commission_and_can_be_reselected(): void
    {
        [, $origin, $destination, , $product] = $this->prepare();
        $quotes = collect($this->quotes($product, $destination)['partners'])->keyBy('delivery_option');
        $this->assertCount(2, $quotes);
        $agency = $quotes['agency_pickup'];
        $home = $quotes['home_delivery'];
        $this->assertEquals(4000, $agency['breakdown']['carrier_price_ht']);
        $this->assertEquals(400, $agency['asso_commission']);
        $this->assertEquals(5170, $agency['delivery_price']);
        $this->assertEquals(6000, $home['breakdown']['carrier_price_ht']);
        $this->assertEquals(600, $home['asso_commission']);
        $this->assertEquals(7755, $home['delivery_price']);
        $this->assertSame('collection', $agency['breakdown']['legs'][0]['kind']);
        $this->assertSame('distribution', $home['breakdown']['legs'][2]['kind']);
        $point = $destination->zone($destination->agency_zone);
        foreach ($quotes as $q) {
            $selected = app(DeliveryQuoteService::class)->quoteFor([['product_id' => $product->id, 'quantity' => 1]],
                $q['company_id'], $q['zone_id'], $q['route_id'], $point['center_latitude'], $point['center_longitude'],
                $destination->city, 'CM', $q['grid_id'], $q['vehicle']);
            $this->assertSame($q['delivery_option'], $selected['delivery_option']);
            $this->assertSame($q['delivery_price'], $selected['delivery_price']);
            $this->assertArrayHasKey('radius_km', $selected['breakdown']['origin_coverage']);
        }
        $order = new Order(['delivery_mode' => 'carrier', 'delivery_city_grid_id' => $destination->id,
            'delivery_breakdown' => ['delivery_option' => 'agency_pickup']]);
        $this->assertTrue($order->isAgencyPickup());
        $this->assertFalse($order->hasLastMileDelivery());
    }

    public function test_outside_destination_circle_only_agency_remains_and_unavailable_route_is_not_bypassed(): void
    {
        [$company, , $destination, $route, $product] = $this->prepare();
        $point = $destination->zone($destination->agency_zone);
        $point['center_latitude'] += 1;
        $this->assertSame(['agency_pickup'], array_column($this->quotes($product, $destination, $point)['partners'], 'delivery_option'));
        $route->update(['is_active' => false]);
        DeliveryRoute::create(['deliverer_company_id' => $company->id, 'origin_country' => 'CM',
            'destination_country' => 'CM', 'is_active' => true, 'bidirectional' => true,
            'pricing_data' => ['ranges' => [['min' => 0, 'max' => 100, 'price' => 1]]]]);
        $this->assertSame([], $this->quotes($product, $destination)['partners']);
    }

    public function test_local_delivery_uses_one_direct_leg_and_coverage_draws_the_exact_circle(): void
    {
        [, $origin, , , $product] = $this->prepare();
        $home = collect($this->quotes($product, $origin)['partners'])->firstWhere('delivery_option', 'home_delivery');
        $this->assertCount(1, $home['breakdown']['legs']);
        $this->assertSame('collection_delivery', $home['breakdown']['legs'][0]['kind']);
        $this->assertEquals(1000, $home['breakdown']['carrier_price_ht']);
        $this->assertNull($home['route_id']);
        $point = $origin->zone($origin->agency_zone);
        $coverage = app(DeliveryQuoteService::class)->coverage($point['center_latitude'], $point['center_longitude']);
        $this->assertTrue($coverage['served']);
        $this->assertEquals(5, $coverage['zones'][0]['radius_km']);
        $this->assertSame('territorial_network', $coverage['served_by'][0]['type']);
    }

    public function test_admin_can_save_radii_and_activate_circles_without_any_quarters(): void
    {
        [$company, $origin] = $this->prepare();
        $zones = collect($origin->zones)->keyBy('code')->all();
        $zones[$origin->agency_zone]['radius_km'] = 17;
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->put(route('admin.delivery-partners.network.grids.update', [$company, $origin]), [
                'zones' => $zones, 'vehicles' => collect($origin->vehicles)->keyBy('code')->all(),
                'agency_zone' => $origin->agency_zone, 'is_active' => 1,
            ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertEquals(17, $origin->fresh()->zone($origin->agency_zone)['radius_km']);
        $this->get(route('admin.delivery-partners.edit', [$company, 'network_department' => $origin->department_code]))->assertOk()->assertSee('Rayon (km)');
        $zones[$origin->agency_zone]['radius_km'] = -1;
        $this->put(route('admin.delivery-partners.network.grids.update', [$company, $origin]), [
            'zones' => $zones, 'vehicles' => collect($origin->vehicles)->keyBy('code')->all(), 'agency_zone' => $origin->agency_zone,
        ])->assertSessionHasErrors();
    }

    public function test_vendor_availability_uses_the_same_territorial_circles_and_individual_radii_as_the_map(): void
    {
        [$company, $origin] = $this->prepare();
        Setting::set('delivery_zone_radius_km', 0.5, 'string');
        $point = $origin->zone($origin->agency_zone);
        $inside = ['latitude' => $point['center_latitude'] + rad2deg(4 / 6371), 'longitude' => $point['center_longitude']];
        $this->getJson('/api/v1/delivery/coverage?'.http_build_query($inside))
            ->assertOk()->assertJsonPath('coverage.served', true);
        $this->postJson('/api/v1/delivery/check-availability', $inside)
            ->assertOk()->assertJsonPath('success', true)->assertJsonPath('available', true)
            ->assertJsonPath('nearest_zone.company.name', 'SOLEX');
        $outside = ['latitude' => $point['center_latitude'] + rad2deg(6 / 6371), 'longitude' => $point['center_longitude']];
        $this->postJson('/api/v1/delivery/check-availability', $outside)->assertOk()->assertJsonPath('available', false);
        $origin->update(['is_active' => false]);
        $this->postJson('/api/v1/delivery/check-availability', $inside)->assertOk()->assertJsonPath('available', false);
        $origin->update(['is_active' => true]);
        $company->update(['is_active' => false]);
        $this->postJson('/api/v1/delivery/check-availability', $inside)->assertOk()->assertJsonPath('available', false);
        $this->postJson('/api/v1/delivery/check-availability', ['latitude' => 100, 'longitude' => 9])->assertUnprocessable();
    }

    public function test_checkout_map_and_quotes_apply_selected_coordinates_for_an_eight_kg_order(): void
    {
        [, $origin, $destination, , $product] = $this->prepare();
        $product->update(['weight' => '2']);
        $point = $destination->zone($destination->agency_zone);
        $params = ['latitude' => $point['center_latitude'], 'longitude' => $point['center_longitude'],
            'items' => [['product_id' => $product->id, 'quantity' => 4]]];
        $this->getJson('/api/v1/delivery/coverage?'.http_build_query($params))->assertOk()
            ->assertJsonPath('coverage.served', true)->assertJsonPath('coverage.geographically_served', true)
            ->assertJsonPath('coverage.quote_available', true)->assertJsonPath('coverage.served_by.0.company_name', 'SOLEX');
        $home = collect($this->getJson('/api/v1/delivery/partners?'.http_build_query($params))->assertOk()->json('partners'))
            ->firstWhere('delivery_option', 'home_delivery');
        $this->assertNotNull($home);
        $this->assertSame($destination->id, $home['grid_id']);
        $this->assertEquals(7755, $home['delivery_price']);
        $point = $origin->zone($origin->agency_zone);
        $params['latitude'] = $point['center_latitude'];
        $params['longitude'] = $point['center_longitude'];
        $local = collect($this->getJson('/api/v1/delivery/partners?'.http_build_query($params))->assertOk()->json('partners'))
            ->firstWhere('delivery_option', 'home_delivery');
        $this->assertSame($origin->id, $local['grid_id']);
        $this->assertCount(1, $local['breakdown']['legs']);
        $this->assertEquals(1000, $local['breakdown']['carrier_price_ht']);
    }

    public function test_map_distinguishes_covered_destination_from_a_shop_without_pickup_coordinates(): void
    {
        [, , $destination, , $product] = $this->prepare();
        $product->shop->update(['latitude' => null, 'longitude' => null]);
        $point = $destination->zone($destination->agency_zone);
        $params = ['latitude' => $point['center_latitude'], 'longitude' => $point['center_longitude'],
            'items' => [['product_id' => $product->id, 'quantity' => 8]]];
        $this->getJson('/api/v1/delivery/coverage?'.http_build_query($params))->assertOk()
            ->assertJsonPath('coverage.served', false)->assertJsonPath('coverage.geographically_served', true)
            ->assertJsonPath('coverage.geographic_served_by.0.company_name', 'SOLEX')
            ->assertJsonPath('coverage.quote_available', false)->assertJsonPath('coverage.quote_reason', 'origin_not_located');
        $this->getJson('/api/v1/delivery/partners?'.http_build_query($params))->assertOk()
            ->assertJsonPath('quote.reason', 'origin_not_located')->assertJsonCount(0, 'partners');
        unset($params['items']);
        $this->postJson('/api/v1/delivery/check-availability', $params)->assertOk()->assertJsonPath('available', true);
    }

    public function test_reverse_direction_and_a_departmental_connection_are_priced(): void
    {
        [, $origin, $destination, , $product] = $this->prepare();
        $point = $destination->zone($destination->agency_zone);
        $product->shop->update(['city' => $destination->city, 'latitude' => $point['center_latitude'], 'longitude' => $point['center_longitude']]);
        $this->assertCount(2, $this->quotes($product, $origin)['partners']);
        // Département non chef-lieu : Wouri → Sanaga-Maritime directement dans le Littoral.
        $other = $this->activate('Sanaga-Maritime', 1000);
        $point = $origin->zone($origin->agency_zone);
        $product->shop->update(['city' => $origin->city, 'latitude' => $point['center_latitude'], 'longitude' => $point['center_longitude']]);
        DeliveryRoute::where('network_level', 'intraregional')
            ->whereIn('origin_territory_code', [$origin->department_code, $other->department_code])
            ->whereIn('destination_territory_code', [$origin->department_code, $other->department_code])
            ->update(['is_active' => true]);
        $home = collect($this->quotes($product, $other)['partners'])->firstWhere('delivery_option', 'home_delivery');
        $this->assertNotNull($home);
        $this->assertSame('intraregional', $home['breakdown']['legs'][1]['kind']);
        $this->assertCount(3, $home['breakdown']['legs']);
    }

    public function test_interregional_non_capital_departments_require_each_useful_connection(): void
    {
        [, , , , $product] = $this->prepare();
        $origin = $this->activate('Sanaga-Maritime', 1000);
        $destination = $this->activate("Nyong-et-So'o", 1000);
        DeliveryRoute::where('network_level', 'intraregional')->update(['is_active' => true]);
        $point = $origin->zone($origin->agency_zone);
        $product->shop->update(['city' => $origin->city, 'latitude' => $point['center_latitude'], 'longitude' => $point['center_longitude']]);
        $home = collect($this->quotes($product, $destination)['partners'])->firstWhere('delivery_option', 'home_delivery');
        $this->assertNotNull($home);
        $this->assertSame(['collection', 'intraregional', 'interregional', 'intraregional', 'distribution'],
            array_column($home['breakdown']['legs'], 'kind'));
        $routeId = $home['breakdown']['legs'][3]['route_id'];
        DeliveryRoute::findOrFail($routeId)->update(['is_active' => false]);
        $this->assertSame([], $this->quotes($product, $destination)['partners']);
    }

    public function test_overlap_selects_the_cheapest_covering_zone_without_adding_a_second_collection(): void
    {
        [, $origin, $destination, , $product] = $this->prepare();
        $zones = $origin->zones;
        $hub = $origin->zone($origin->agency_zone);
        $other = collect($zones)->firstWhere('code', '!=', $origin->agency_zone);
        foreach ($zones as &$z) {
            if ($z['code'] === $other['code']) {
                $z['is_active'] = true;
                $z['center_latitude'] = $hub['center_latitude'];
                $z['center_longitude'] = $hub['center_longitude'];
            }
        }
        $vehicle = $origin->vehicles[0];
        $key = min($other['code'], $hub['code']).'-'.max($other['code'], $hub['code']);
        $vehicle['prices'][$key] = 500;
        $origin->update(['zones' => $zones, 'vehicles' => [$vehicle]]);
        $quotes = collect($this->quotes($product, $destination)['partners']);
        $this->assertCount(2, $quotes);
        foreach ($quotes as $quote) {
            $this->assertEquals(500, $quote['breakdown']['legs'][0]['price']);
            $this->assertCount(1, array_filter($quote['breakdown']['legs'], fn ($l) => $l['kind'] === 'collection'));
        }
    }

    public function test_overlapping_departments_still_present_only_two_cheapest_options(): void
    {
        [, $origin, $destination, , $product] = $this->prepare();
        $other = $this->activate('Sanaga-Maritime', 1000);
        $point = $destination->zone($destination->agency_zone);
        $zones = $other->zones;
        foreach ($zones as &$zone) {
            if ($zone['is_active']) {
                $zone['center_latitude'] = $point['center_latitude'];
                $zone['center_longitude'] = $point['center_longitude'];
            }
        }
        $other->update(['zones' => $zones]);
        DeliveryRoute::where('network_level', 'intraregional')
            ->whereIn('origin_territory_code', [$origin->department_code, $other->department_code])
            ->whereIn('destination_territory_code', [$origin->department_code, $other->department_code])
            ->update(['is_active' => true, 'pricing_data' => json_encode(['ranges' => [['min' => 0, 'max' => 100, 'price' => 1000]]])]);
        $quotes = collect($this->quotes($product, $destination)['partners']);
        $this->assertCount(2, $quotes);
        $home = $quotes->firstWhere('delivery_option', 'home_delivery');
        $this->assertEquals(3000, $home['breakdown']['carrier_price_ht']);
        $this->assertSame($other->id, $home['grid_id']);
    }

    public function test_paid_order_keeps_collection_agency_and_radius_snapshot_after_configuration_changes(): void
    {
        [, , $destination, , $product] = $this->prepare();
        \App\Services\CommissionService::flush();
        $this->mock(\App\Services\FirebaseMessagingService::class, fn ($m) => $m->shouldReceive('sendToUser')->andReturn([]));
        $this->mock(\App\Services\FcmService::class, fn ($m) => $m->shouldIgnoreMissing());
        $buyer = User::factory()->create();
        \App\Models\WalletBalance::create(['user_id' => $buyer->id, 'currency' => 'XAF', 'balance' => 100000, 'locked_balance' => 0]);
        $q = collect($this->quotes($product, $destination)['partners'])->firstWhere('delivery_option', 'agency_pickup');
        $point = $destination->zone($destination->agency_zone);
        $id = $this->actingAs($buyer, 'sanctum')->postJson('/api/v1/orders', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'delivery_company_id' => $q['company_id'], 'delivery_route_id' => $q['route_id'],
            'delivery_grid_id' => $q['grid_id'], 'delivery_vehicle' => $q['vehicle'],
            'delivery_latitude' => $point['center_latitude'], 'delivery_longitude' => $point['center_longitude'],
            'delivery_city' => $destination->city, 'delivery_country' => 'CM',
            'payment_mode' => 'wallet', 'wallet_provider' => 'kpay', 'customer_phone' => '237677000000',
        ])->assertCreated()->json('order_id');
        $order = Order::findOrFail($id);
        $this->assertTrue($order->isAgencyPickup());
        $this->assertEquals(5170, $order->delivery_breakdown['total']);
        $this->assertEquals(400, $order->delivery_commission);
        $this->assertEquals(5, $order->delivery_breakdown['destination_coverage']['radius_km']);
        $this->assertSame('collection', $order->delivery_breakdown['legs'][0]['kind']);
        $this->assertSame('Rue du marché', $order->pickup_agency_snapshot['address']);
        $destination->update(['is_active' => false]);
        DeliveryAgency::findOrFail($order->delivery_agency_id)->update(['address' => 'Nouvelle adresse']);
        $this->assertSame('Rue du marché', $order->fresh()->pickup_agency_snapshot['address']);
        $this->assertEquals(5170, $order->fresh()->delivery_breakdown['total']);
    }
    public function test_heavy_weight_pricing_preserves_custom_prices_and_activates_provisional_agencies(): void
    {
        (new SolexNetworkSeeder)->run();
        $grid = DeliveryCityGrid::whereNotNull('department_code')->firstOrFail();
        $truck = $grid->vehicle('heavy');
        $this->assertNull($truck['max_weight_kg']);
        $this->assertSame('excess_per_kg', $truck['billing']);
        $this->assertSame('1t', $truck['base_vehicle']);
        $this->assertSame([], $truck['prices']);
        $agency = DeliveryAgency::where('is_active', true)->firstOrFail();
        $this->assertStringContainsString('Centre-ville', $agency->address);
        $this->assertStringContainsString('confirmer', $agency->phone);
        $vehicles = $grid->vehicles;
        foreach ($vehicles as &$vehicle) {
            if ($vehicle['code'] === 'heavy') {
                $vehicle['prices'] = ['1-1' => 12345];
            }
        }
        $grid->update(['vehicles' => $vehicles]);
        (new SolexNetworkSeeder)->run();
        $this->assertSame(['1-1' => 12345], $grid->fresh()->vehicle('heavy')['prices']);
        $this->assertSame(1, collect($grid->fresh()->vehicles)->where('code', 'heavy')->count());
    }

    public function test_2500_kg_gets_home_and_agency_options_when_heavy_vehicle_is_configured(): void
    {
        [, $origin, $destination, $route, $product] = $this->prepare();
        $product->update(['weight' => '50']);
        $route->update(['pricing_data' => ['ranges' => [['min' => 0, 'max' => 3000, 'price' => 3000]]]]);
        foreach ([$origin, $destination] as $grid) {
            $truck = $grid->vehicles[0];
            $truck['code'] = '1t';
            $truck['label'] = 'Camion 1 tonne';
            $truck['max_weight_kg'] = 1000;
            $base = $grid->id === $origin->id ? 4000 : 6000;
            $truck['prices'] = array_map(fn () => $base, $truck['prices']);
            $heavy = ['code' => 'heavy', 'label' => 'Transport lourd au kilo', 'min_weight_kg' => 1000,
                'max_weight_kg' => null, 'prices' => [], 'base_vehicle' => '1t',
                'billing' => 'excess_per_kg', 'included_weight_kg' => 1000];
            $grid->update(['vehicles' => [...$grid->vehicles, $truck, $heavy]]);
            $this->assertSame('1t', $grid->vehicleFor(1000, $grid->agency_zone, $grid->agency_zone)['vehicle']['code']);
        }
        $point = $destination->zone($destination->agency_zone);
        $result = app(DeliveryQuoteService::class)->quotes([['product_id' => $product->id, 'quantity' => 50]],
            $point['center_latitude'], $point['center_longitude'], $destination->city, 'CM');
        $this->assertTrue($result['available']);
        $this->assertEquals(2500, $result['weight_kg']);
        $offers = collect($result['partners'])->keyBy('delivery_option');
        $this->assertCount(2, $offers);
        $this->assertSame('home_delivery:heavy', $offers['home_delivery']['vehicle']);
        $this->assertSame('agency_pickup:heavy', $offers['agency_pickup']['vehicle']);
        $this->assertEquals(28000, $offers['home_delivery']['breakdown']['carrier_price_ht']);
        $this->assertEquals(13000, $offers['agency_pickup']['breakdown']['carrier_price_ht']);
        $collection = $offers['home_delivery']['breakdown']['legs'][0];
        $this->assertEquals(1500, $collection['extra_kg']);
        $this->assertEquals(4, $collection['extra_per_kg']);
        $this->assertEquals(6000, $collection['extra_price']);
        $this->assertEquals(10000, $collection['price']);
        $this->assertEquals(6, $offers['home_delivery']['breakdown']['legs'][2]['extra_per_kg']);
        $heavy = $origin->vehicles;
        $heavy[array_key_last($heavy)]['extra_per_kg'] = 10;
        $origin->update(['vehicles' => $heavy]);
        $this->assertEquals(19000, $origin->vehicleFor(2500, $origin->agency_zone, $origin->agency_zone)['price']);
    }

    public function test_covered_destination_reports_capacity_and_inactive_agency_instead_of_no_partner(): void
    {
        [, , $destination, $route, $product] = $this->prepare();
        $product->update(['weight' => '50']);
        $route->update(['pricing_data' => ['ranges' => [['min' => 0, 'max' => 3000, 'price' => 3000]]]]);
        DeliveryAgency::where('city', $destination->city)->update(['is_active' => false]);
        $point = $destination->zone($destination->agency_zone);
        $params = ['latitude' => $point['center_latitude'], 'longitude' => $point['center_longitude'],
            'items' => [['product_id' => $product->id, 'quantity' => 50]]];
        $response = $this->getJson('/api/v1/delivery/partners?'.http_build_query($params))->assertOk();
        $response->assertJsonPath('quote.reason', 'network_configuration_incomplete')->assertJsonCount(0, 'partners');
        $reasons = array_column($response->json('quote.configuration_issues'), 'reason');
        $this->assertContains('weight_limit', $reasons);
        $this->assertContains('agency_unavailable', $reasons);
        $this->assertStringNotContainsString('Aucun partenaire', $response->json('message'));
        $this->getJson('/api/v1/delivery/coverage?'.http_build_query($params))->assertOk()
            ->assertJsonPath('coverage.geographically_served', true)
            ->assertJsonPath('coverage.quote_reason', 'network_configuration_incomplete');
    }

}
