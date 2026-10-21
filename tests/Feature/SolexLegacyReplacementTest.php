<?php

namespace Tests\Feature;

use App\Models\DelivererCompany;
use App\Models\DeliveryCityGrid;
use App\Models\DeliveryRoute;
use App\Models\DeliveryZone;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SolexLegacyReplacementTest extends TestCase
{
    use RefreshDatabase;

    private function legacy(DelivererCompany $company, string $city): array
    {
        return [
            DeliveryCityGrid::create(['deliverer_company_id' => $company->id, 'city' => $city,
                'country' => 'CM', 'zones' => [], 'vehicles' => [], 'is_active' => true]),
            DeliveryRoute::create(['deliverer_company_id' => $company->id, 'origin_country' => 'CM',
                'destination_country' => 'CM', 'origin_city' => $city, 'destination_city' => 'Yaoundé',
                'bidirectional' => true, 'is_active' => true,
                'pricing_data' => ['ranges' => [['min' => 0, 'max' => 10, 'price' => 1111]]]]),
            DeliveryZone::create(['deliverer_company_id' => $company->id, 'name' => $city,
                'city' => $city, 'center_latitude' => 4.05, 'center_longitude' => 9.77, 'is_active' => true]),
        ];
    }

    public function test_dry_run_does_not_delete_disable_seed_or_activate_anything(): void
    {
        $company = DelivererCompany::create(['name' => 'SOLEX', 'is_active' => false]);
        $records = $this->legacy($company, 'Douala');
        $this->artisan('solex:sync', ['--replace-legacy' => true, '--dry-run' => true])->assertSuccessful();
        $this->assertDatabaseCount('delivery_territories', 0);
        $this->assertFalse($company->fresh()->is_active);
        $this->assertFalse($company->fresh()->territorial_network_enabled);
        foreach ($records as $record) {
            $this->assertNotNull($record->fresh());
            $this->assertTrue($record->fresh()->is_active);
        }
    }

    public function test_replacement_removes_unused_legacy_and_preserves_orders_and_other_carriers(): void
    {
        $company = DelivererCompany::create(['name' => 'SOLEX', 'is_active' => true]);
        [$grid, $route, $zone] = $this->legacy($company, 'Douala');
        $unused = $this->legacy($company, 'Bafoussam');
        $other = DelivererCompany::create(['name' => 'Autre transporteur', 'is_active' => true]);
        $otherRecords = $this->legacy($other, 'Douala');
        $buyer = User::factory()->create();
        $orders = collect(['confirmed', 'delivered'])->map(fn ($status) => Order::create([
            'user_id' => $buyer->id, 'status' => $status, 'payment_status' => Order::PAYMENT_PAID,
            'delivery_mode' => Order::DELIVERY_CARRIER, 'delivery_company_id' => $company->id,
            'delivery_city_grid_id' => $grid->id, 'delivery_route_id' => $route->id, 'delivery_zone_id' => $zone->id,
            'subtotal' => 10000, 'delivery_fee' => 2000, 'total' => 12000, 'payment_method' => 'kpay_direct',
            'delivery_breakdown' => ['total' => 2000, 'delivery_option' => 'home_delivery'],
        ]));
        $snapshots = $orders->map(fn ($order) => $order->fresh()->getRawOriginal());

        $this->artisan('solex:sync', ['--replace-legacy' => true])->assertSuccessful();

        foreach ($unused as $record) {
            $this->assertNull($record->fresh());
        }
        foreach ([$grid, $route, $zone] as $record) {
            $this->assertNotNull($record->fresh());
            $this->assertFalse($record->fresh()->is_active);
        }
        foreach ($otherRecords as $record) {
            $this->assertNotNull($record->fresh());
            $this->assertTrue($record->fresh()->is_active);
        }
        foreach ($orders as $index => $order) {
            $this->assertEquals($snapshots[$index], $order->fresh()->getRawOriginal());
        }
        $this->assertTrue($company->fresh()->territorial_network_enabled);
        $this->assertSame(58, $company->cityGrids()->whereNotNull('department_code')->where('is_active', true)->count());
        $this->assertSame(203, $company->deliveryRoutes()->whereNotNull('network_key')->where('is_active', true)->count());
        $this->assertSame(360, $company->cityGrids()->whereNotNull('department_code')->get()->sum(fn ($g) => collect($g->zones)->where('is_active', true)->count()));
        // A second deployment must not recreate or remove any historical reference.
        $this->artisan('solex:sync', ['--replace-legacy' => true])->assertSuccessful();
        $this->assertDatabaseCount('orders', 2);
        $this->assertSame(58, $company->cityGrids()->whereNotNull('department_code')->count());
        $this->assertNotNull($route->fresh());
    }
}
