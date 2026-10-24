<?php

namespace Tests\Feature;

use App\Models\DelivererCompany;
use App\Models\DeliveryAgency;
use App\Models\DeliveryRoute;
use App\Models\Order;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\InternationalCarrierDemoService;
use Database\Seeders\DhlFedexProductionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class InternationalCarrierProductionTest extends TestCase
{
    use RefreshDatabase;

    public function test_standalone_production_seed_configures_both_directions_without_imports_or_demo_accounts(): void
    {
        $this->app->instance('env', 'production');
        $this->artisan('delivery:configure-carriers')->assertSuccessful();
        $this->assertSame(3, DelivererCompany::count());
        $this->assertSame(0, User::count());
        $this->assertSame(0, Order::count());
        $this->assertSame(0, WalletTransaction::count());
        $this->assertDatabaseCount('partner_freight_rates', 0);
        $this->assertDatabaseCount('maritime_departures', 0);
        foreach (['DHL Express', 'FedEx'] as $name) {
            $company = DelivererCompany::where('name', $name)->firstOrFail();
            $routes = $company->deliveryRoutes()->whereNotNull('export_config')->get();
            $this->assertCount(249, $routes);
            foreach ($routes as $route) {
                $this->assertSame('per_kg', $route->pricing_data['mode']);
                $this->assertSame($route->pricing_data, $route->export_config['return_pricing']);
                $this->assertTrue($route->export_config['return_pricing_provisional']);
                $this->assertNotNull($route->export_config['solex_agency_id']);
            }
        }
        $this->assertSame(200, DeliveryAgency::where('is_international_reference', true)->where('is_provisional', false)->count());
        $this->assertSame(498, DeliveryAgency::where('is_international_reference', true)->where('is_provisional', true)->count());
        $this->artisan('delivery:configure-carriers')->assertSuccessful();
        $this->assertSame(498, DeliveryRoute::whereNotNull('export_config')->count());
        $this->assertSame(698, DeliveryAgency::where('is_international_reference', true)->count());
    }

    public function test_rerun_preserves_separate_tariffs_solex_prices_agencies_and_disabled_partners(): void
    {
        $this->artisan('delivery:configure-carriers')->assertSuccessful();
        $route = DeliveryRoute::where('destination_country', 'FR')->whereNotNull('export_config')->firstOrFail();
        $config = $route->export_config;
        $config['return_pricing'] = ['mode' => 'per_kg', 'price_per_kg' => 5432];
        $config['return_pricing_provisional'] = false;
        $route->update(['pricing_data' => ['mode' => 'per_kg', 'price_per_kg' => 7777], 'export_config' => $config, 'is_active' => false]);
        $solex = DelivererCompany::where('name', 'SOLEX')->firstOrFail();
        $solex->update(['is_active' => false, 'territorial_network_enabled' => false]);
        $agency = DeliveryAgency::findOrFail($config['solex_agency_id']);
        $price = ['ranges' => [['max' => 5, 'price' => 1700]], 'price_per_km' => 200];
        $agency->update(['is_active' => false, 'address' => 'Agence réelle corrigée', 'collection_pricing' => $price]);
        $carrier = DelivererCompany::where('name', 'DHL Express')->firstOrFail();
        $carrier->update(['is_active' => false]);
        $this->artisan('delivery:configure-carriers')->assertSuccessful();
        $this->assertSame(7777, $route->fresh()->pricing_data['price_per_kg']);
        $this->assertSame($config, $route->fresh()->export_config);
        $this->assertFalse($route->fresh()->is_active);
        $this->assertFalse($carrier->fresh()->is_active);
        $this->assertFalse($solex->fresh()->is_active);
        $this->assertFalse($solex->fresh()->territorial_network_enabled);
        $this->assertFalse($agency->fresh()->is_active);
        $this->assertSame($price, $agency->fresh()->collection_pricing);
        $this->assertSame('Agence réelle corrigée', $agency->fresh()->address);
    }

    public function test_initializing_missing_routes_does_not_reactivate_existing_disabled_companies(): void
    {
        foreach (['DHL Express', 'FedEx', 'SOLEX'] as $name) {
            DelivererCompany::create(['name' => $name, 'service_type' => 'international', 'is_active' => false]);
        }
        $this->artisan('delivery:configure-carriers')->assertSuccessful();
        $this->assertSame(0, DelivererCompany::where('is_active', true)->count());
        $this->assertSame(498, DeliveryRoute::whereNotNull('export_config')->count());
    }

    public function test_missing_migrations_and_test_tracking_mode_fail_before_any_seed_writes(): void
    {
        $this->app->instance('env', 'production');
        config(['shipping.export_test_tracking' => true]);
        $this->artisan('delivery:configure-carriers')->assertFailed();
        $this->assertThrows(fn () => app(DhlFedexProductionSeeder::class)->run(), \RuntimeException::class);
        $this->assertSame(0, DelivererCompany::count());
        config(['shipping.export_test_tracking' => false]);
        Schema::table('delivery_agencies', fn ($table) => $table->dropColumn('collection_pricing'));
        $this->artisan('delivery:configure-carriers')->assertFailed();
        $this->assertSame(0, DelivererCompany::count());
        $this->assertSame(0, User::count());
    }

    public function test_seed_rolls_back_the_solex_network_when_export_configuration_fails(): void
    {
        $this->mock(InternationalCarrierDemoService::class, fn ($mock) => $mock->shouldReceive('load')->andThrow(new \RuntimeException('Import indisponible')));
        $this->assertThrows(fn () => app(DhlFedexProductionSeeder::class)->run(), \RuntimeException::class);
        $this->assertSame(0, DelivererCompany::count());
        $this->assertDatabaseCount('delivery_territories', 0);
        $this->assertDatabaseCount('delivery_agencies', 0);
    }
}
