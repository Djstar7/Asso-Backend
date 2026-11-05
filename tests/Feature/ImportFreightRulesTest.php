<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\DeliveryCityGrid;
use App\Models\ImportCountry;
use App\Models\ImportShippingOption;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductPriceTier;
use App\Models\Setting;
use App\Models\Shop;
use App\Models\User;
use App\Models\WalletBalance;
use App\Services\AssoShipmentService;
use App\Services\CommissionService;
use App\Services\FcmService;
use App\Services\FirebaseMessagingService;
use App\Services\ImportFreightService;
use App\Support\ImportHub;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Import : bateau au CBM, avion plafonné au seuil du pays, marge ASSO, dimensions
 * facultatives et retrait à l'entrepôt avec code au-delà du seuil.
 */
class ImportFreightRulesTest extends TestCase
{
    use RefreshDatabase;

    private DeliveryCityGrid $grid;

    private Product $product;

    private ProductPriceTier $tier;

    private ImportShippingOption $air;

    private ImportShippingOption $sea;

    private User $buyer;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        CommissionService::flush();
        $this->mock(FirebaseMessagingService::class, fn ($mock) => $mock->shouldReceive('sendToUser')->andReturn([]));
        $this->mock(FcmService::class, fn ($mock) => $mock->shouldIgnoreMissing());
        Setting::set('delivery_vat_rate', '19.25', 'string', 'delivery');
        User::factory()->create(['email' => 'admin@asso.com']);
        (new \Database\Seeders\DeliveryPartnersSeeder())->run();
        $this->grid = DeliveryCityGrid::where('city', 'Douala')->firstOrFail();
        ImportHub::ensureShop()->update(['address' => 'Rue 1.234, Akwa-Nord, Douala', 'phone' => '237670000002']);

        $supplier = User::factory()->create();
        $shop = Shop::create([
            'user_id' => $supplier->id, 'name' => 'Usine', 'slug' => 'usine-'.uniqid(),
            'status' => 'active', 'verified_at' => now(), 'city' => 'Guangzhou', 'country' => 'Chine',
        ]);
        ImportCountry::create(['code' => 'CN', 'name' => 'Chine', 'is_active' => true]);
        $this->product = Product::create([
            'user_id' => $supplier->id, 'shop_id' => $shop->id,
            'category_id' => Category::create(['name' => 'Divers', 'slug' => 'divers-'.uniqid()])->id,
            'name' => 'Carton', 'slug' => 'carton-'.uniqid(), 'price' => 1000, 'currency' => 'XAF',
            'stock' => 0, 'status' => 'active', 'is_wholesale' => true, 'origin_country' => 'CN', 'weight' => '8',
        ]);
        $this->tier = ProductPriceTier::create([
            'product_id' => $this->product->id, 'label' => 'Unité', 'unit_price' => 1000,
            'currency' => 'XAF', 'min_quantity' => 1, 'is_active' => true,
        ]);
        $this->air = ImportShippingOption::create([
            'country_code' => 'CN', 'mode' => 'air', 'rate_type' => 'per_kg', 'rate_amount' => 12000,
            'currency' => 'XAF', 'lead_time_days' => 9, 'is_active' => true, 'sort_order' => 1,
        ]);
        $this->sea = ImportShippingOption::create([
            'country_code' => 'CN', 'mode' => 'sea', 'rate_type' => 'per_cbm', 'rate_amount' => 330000,
            'currency' => 'XAF', 'lead_time_days' => 45, 'is_active' => true, 'sort_order' => 2,
        ]);
        $this->buyer = User::factory()->create();
        WalletBalance::updateOrCreate(['user_id' => $this->buyer->id, 'currency' => 'XAF'], ['balance' => 90_000_000, 'locked_balance' => 0]);
        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    /** Commande livrée par SOLEX, avec le véhicule proposé pour ce poids. */
    private function order(ImportShippingOption $option, int $quantity, array $extra = [])
    {
        $offers = $this->getJson('/api/v1/delivery/partners?'.http_build_query([
            'items' => [['product_id' => $this->product->id, 'quantity' => $quantity]],
            'city' => 'Douala', 'quarter' => 'Bonapriso', 'country' => 'Cameroun',
        ]))->assertOk()->json('partners');
        $vehicle = collect($offers)->firstWhere('grid_id', $this->grid->id)['vehicle'] ?? 'moto';

        return $this->actingAs($this->buyer, 'sanctum')->postJson('/api/v1/import/orders', $extra + [
            'items' => [['product_id' => $this->product->id, 'price_tier_id' => $this->tier->id, 'quantity' => $quantity]],
            'shipping_option_id' => $option->id,
            'payment_mode' => 'wallet',
            'delivery_company_id' => $this->grid->deliverer_company_id,
            'delivery_grid_id' => $this->grid->id,
            'delivery_vehicle' => $vehicle,
            'delivery_quarter' => 'Bonapriso',
            'delivery_city' => 'Douala',
            'delivery_address' => 'Bonapriso, Douala',
            'customer_phone' => '690000000',
        ]);
    }

    private function pickup(ImportShippingOption $option, int $quantity)
    {
        return $this->actingAs($this->buyer, 'sanctum')->postJson('/api/v1/import/orders', [
            'items' => [['product_id' => $this->product->id, 'price_tier_id' => $this->tier->id, 'quantity' => $quantity]],
            'shipping_option_id' => $option->id, 'payment_mode' => 'wallet',
            'warehouse_pickup' => true, 'customer_phone' => '690000000',
        ]);
    }

    public function test_boxes_with_dimensions_bill_air_by_real_weight_and_sea_by_cbm(): void
    {
        $this->product->update(['length_cm' => 60, 'width_cm' => 40, 'height_cm' => 50]);

        $air = Order::find($this->order($this->air, 3)->assertCreated()->json('order_id'));
        $this->assertEquals(288000, $air->import_shipping_fee);
        $this->assertEquals(24, $air->shipping_weight_kg);
        $this->assertEquals(24, $air->delivery_breakdown['import_freight']['billed_quantity']);

        $sea = Order::find($this->order($this->sea, 3)->assertCreated()->json('order_id'));
        $this->assertEquals(118800, $sea->import_shipping_fee);
        $this->assertEquals(0.36, $sea->delivery_breakdown['import_freight']['volume_cbm']);
    }

    public function test_without_dimensions_only_weight_counts_with_1000_kg_per_cbm(): void
    {
        $this->assertEquals(288000, Order::find($this->order($this->air, 3)->assertCreated()->json('order_id'))->import_shipping_fee);
        $this->assertEquals(7920, Order::find($this->order($this->sea, 3)->assertCreated()->json('order_id'))->import_shipping_fee);
    }

    public function test_tier_dimensions_win_over_product_dimensions(): void
    {
        $this->product->update(['length_cm' => 10, 'width_cm' => 10, 'height_cm' => 10]);
        $this->tier->update(['length_cm' => 100, 'width_cm' => 100, 'height_cm' => 100]);

        $this->assertEquals(330000, Order::find($this->order($this->sea, 1)->assertCreated()->json('order_id'))->import_shipping_fee);
    }

    public function test_the_chosen_dimensions_option_wins_and_free_text_is_ignored(): void
    {
        $this->product->update(['length_cm' => 10, 'width_cm' => 10, 'height_cm' => 10]);
        $option = fn (string $value) => $this->product->variants()->create(['attributes' => ['Dimensions' => $value], 'stock' => 10, 'is_active' => true]);
        $order = fn ($variant) => Order::find($this->order($this->sea, 1, ['items' => [[
            'product_id' => $this->product->id, 'price_tier_id' => $this->tier->id, 'variant_id' => $variant->id, 'quantity' => 1,
        ]]])->assertCreated()->json('order_id'));

        // 200 × 100 × 50 cm = 1 CBM ; une valeur libre laisse les dimensions de la fiche.
        $this->assertEquals(330000, $order($option('200 × 100 × 50 cm'))->import_shipping_fee);
        $this->assertEquals(2640, $order($option('Grande taille'))->import_shipping_fee);
    }

    public function test_client_measures_are_ignored(): void
    {
        $id = $this->order($this->sea, 3, ['shipping_cbm' => 0.001, 'shipping_weight_kg' => 1])->assertCreated()->json('order_id');

        $this->assertEquals(7920, Order::find($id)->import_shipping_fee);
    }

    public function test_air_stops_above_the_common_limit_while_sea_stays_available(): void
    {
        // 12 × 8 = 96 kg : avion et bateau ; 13 × 8 = 104 kg : bateau seulement.
        $this->order($this->air, 12)->assertCreated();
        $this->order($this->air, 13)->assertStatus(422)->assertJsonPath('message', __('orders.wholesale.air_weight_limit', ['weight' => 100]));
        $this->order($this->sea, 13)->assertCreated();

        Setting::set(ImportFreightService::AIR_MAX_SETTING, '200', 'string', 'import');
        $this->order($this->air, 13)->assertCreated();
    }

    public function test_only_real_weight_counts_towards_the_air_limit(): void
    {
        $this->product->update(['weight' => '1', 'length_cm' => 100, 'width_cm' => 100, 'height_cm' => 100]);

        $this->order($this->air, 1)->assertCreated();
    }

    public function test_air_needs_a_weight_while_sea_can_use_dimensions_alone(): void
    {
        $this->product->update(['weight' => null, 'length_cm' => 100, 'width_cm' => 100, 'height_cm' => 100]);

        $this->order($this->air, 1)->assertStatus(422)->assertJsonPath('message', __('orders.parcel_weight_required'));
        $this->order($this->sea, 1)->assertCreated();
    }

    public function test_a_car_is_billed_by_volume(): void
    {
        $this->product->update(['weight' => '1200', 'length_cm' => 450, 'width_cm' => 180, 'height_cm' => 150]);

        $order = Order::find($this->pickup($this->sea, 1)->assertCreated()->json('order_id'));
        $this->assertEquals(4009500, $order->import_shipping_fee);
    }

    public function test_express_is_no_longer_offered_or_accepted(): void
    {
        $express = ImportShippingOption::create([
            'country_code' => 'CN', 'mode' => 'express', 'rate_type' => 'flat', 'rate_amount' => 300000,
            'currency' => 'XAF', 'lead_time_days' => 3, 'is_active' => true,
        ]);

        $modes = collect($this->getJson('/api/v1/import/CN/products')->assertOk()->json('shipping_options'))->pluck('mode')->all();
        $this->assertEqualsCanonicalizing(['air', 'sea'], $modes);
        $this->order($express, 1)->assertStatus(422);
    }

    public function test_catalog_exposes_freight_rules_and_dimensions(): void
    {
        $this->product->update(['length_cm' => 60, 'width_cm' => 40, 'height_cm' => 50]);
        $this->tier->update(['length_cm' => 70, 'width_cm' => 40, 'height_cm' => 50]);

        $this->getJson('/api/v1/import/CN/products')->assertOk()
            ->assertJsonPath('products.0.freight_rules.air_max_weight_kg', 100)
            ->assertJsonPath('products.0.freight_rules.pickup_threshold_kg', 300)
            ->assertJsonPath('products.0.length_cm', 60)
            ->assertJsonPath('products.0.price_tiers.0.length_cm', 70);
    }

    public function test_admin_saves_cost_and_margin_as_client_rate_and_snapshots_them(): void
    {
        $country = ImportCountry::where('code', 'CN')->first();
        $this->actingAs($this->admin)->putJson(route('admin.import-countries.shipping-options.update', [$country, $this->sea]), [
            'mode' => 'sea', 'cost_amount' => 330000, 'margin_type' => 'percent', 'margin_value' => 10, 'lead_time_days' => 45,
        ])->assertOk();
        $this->assertEquals(363000, $this->sea->refresh()->rate_amount);
        $this->assertSame('per_cbm', $this->sea->rate_type);

        $this->actingAs($this->admin)->putJson(route('admin.import-countries.shipping-options.update', [$country, $this->air]), [
            'mode' => 'air', 'cost_amount' => 12000, 'margin_type' => 'amount', 'margin_value' => 1000, 'lead_time_days' => 9,
        ])->assertOk();
        $this->assertEquals(13000, $this->air->refresh()->rate_amount);

        $this->product->update(['weight' => '1000']);
        $order = Order::find($this->pickup($this->sea, 1)->assertCreated()->json('order_id'));
        $freight = $order->delivery_breakdown['import_freight'];
        $this->assertEquals(363000, $order->import_shipping_fee);
        $this->assertEquals(330000, $freight['cost_total']);
        $this->assertEquals(33000, $freight['margin_total']);
        $this->assertEquals(33000, $order->import_freight_margin);

        // Le client ne voit jamais le coût d'achat ni la marge.
        $option = collect($this->getJson('/api/v1/import/CN/products')->json('shipping_options'))->firstWhere('mode', 'sea');
        $this->assertArrayNotHasKey('cost_amount', $option);
        $this->assertArrayNotHasKey('margin_value', $option);
    }

    public function test_admin_can_no_longer_create_express_or_flat_and_sets_rules_for_all_countries(): void
    {
        $country = ImportCountry::where('code', 'CN')->first();
        $this->actingAs($this->admin)->postJson(route('admin.import-countries.shipping-options.store', $country), [
            'mode' => 'express', 'cost_amount' => 100, 'lead_time_days' => 3,
        ])->assertStatus(422);

        $this->actingAs($this->admin)->put(route('admin.import-countries.freight-rules'), [
            'air_max_weight_kg' => 150, 'pickup_threshold_kg' => 500,
        ])->assertSessionHasNoErrors();
        $this->assertEquals(150, ImportFreightService::airMaxWeightKg());
        $this->assertEquals(500, ImportHub::pickupThresholdKg());

        $this->actingAs($this->admin)->put(route('admin.import-countries.freight-rules'), [
            'air_max_weight_kg' => 150, 'pickup_threshold_kg' => 0,
        ])->assertSessionHasErrors('pickup_threshold_kg');
        $this->assertEquals(500, ImportHub::pickupThresholdKg());
    }

    public function test_below_pickup_threshold_solex_stays_and_above_it_old_apps_must_update(): void
    {
        // 300 kg pile : encore SOLEX ; 304 kg : retrait obligatoire.
        $this->product->update(['weight' => '4']);
        $id = $this->order($this->sea, 75)->assertCreated()->json('order_id');
        $this->assertFalse(Order::find($id)->isWarehousePickup());

        $this->order($this->sea, 76)->assertStatus(422)->assertJsonPath('message', __('orders.wholesale.pickup_requires_update'));
        $this->pickup($this->sea, 76)->assertCreated();

        // Sous le seuil, demander le retrait ne dispense pas de SOLEX.
        $this->pickup($this->sea, 10)->assertStatus(422);
    }

    public function test_pickup_threshold_is_configurable(): void
    {
        $this->product->update(['weight' => '4']);
        $hub = ImportHub::shop();
        $this->actingAs($this->admin)->put(route('admin.import-countries.freight-rules'), [
            'air_max_weight_kg' => 100, 'pickup_threshold_kg' => 500,
        ])->assertSessionHasNoErrors();

        $this->assertEquals(500, ImportHub::pickupThresholdKg());
        $this->order($this->sea, 76)->assertCreated();
        $this->assertSame($hub->id, ImportHub::shop()->id);
    }

    /** Commande payée, validée, arrivée à Douala. */
    private function arrivedPickup(): Order
    {
        $this->product->update(['weight' => '400']);
        $order = Order::find($this->pickup($this->sea, 1)->assertCreated()->json('order_id'));
        $this->assertTrue($order->isWarehousePickup());
        $this->assertEquals($order->import_shipping_fee, $order->delivery_fee);
        $this->assertNull($order->delivery_company_id);

        $this->actingAs($this->admin)->post(route('admin.wholesale-orders.confirm', $order))->assertSessionHas('success');
        $service = app(AssoShipmentService::class);
        $service->advance($order, ['step' => 'handed_to_carrier'], $this->admin);
        $service->advance($order, ['step' => 'in_transit'], $this->admin);
        $service->advance($order, ['step' => 'arrived_hub'], $this->admin);

        return $order->fresh();
    }

    public function test_full_warehouse_pickup_with_code(): void
    {
        $order = $this->arrivedPickup();
        $code = $order->confirmation_code;

        // Pas d'enlèvement SOLEX, et aucune remise possible sans code.
        $this->assertSame([], AssoShipmentService::nextSteps($order));
        $this->assertNull($order->carrier_courier_requested_at);

        $this->actingAs($this->buyer, 'sanctum')->getJson("/api/v1/orders/{$order->id}")->assertOk()
            ->assertJsonPath('order.confirmation_code', $code)
            ->assertJsonPath('order.delivery.delivery_option', 'warehouse_pickup');

        $this->actingAs($this->admin)->get(route('admin.wholesale-orders.show', $order))->assertOk()->assertSee('Valider la remise');

        $this->actingAs($this->admin)->post(route('admin.wholesale-orders.collect-at-warehouse', $order), ['code' => $code === '000000' ? '000001' : '000000'])
            ->assertSessionHas('error');
        $this->assertSame('shipped', $order->fresh()->status);

        $this->actingAs($this->admin)->post(route('admin.wholesale-orders.collect-at-warehouse', $order), ['code' => $code])
            ->assertSessionHas('success');
        $order->refresh();
        $this->assertSame('delivered', $order->status);
        $this->assertNull($order->confirmation_code);
        $this->assertNotNull($order->delivered_at);
    }

    public function test_deposit_order_gets_its_code_only_after_the_balance(): void
    {
        $this->product->update(['deposit_enabled' => true, 'deposit_rate' => 30]);
        $order = $this->arrivedPickup();
        $this->assertTrue($order->isDepositOrder());
        $this->assertSame(Order::VERIFICATION_TO_CONTACT, $order->verification_status);

        $this->actingAs($this->buyer, 'sanctum')->getJson("/api/v1/orders/{$order->id}")->assertOk()->assertJsonMissingPath('order.confirmation_code');
        $this->actingAs($this->admin)->post(route('admin.wholesale-orders.collect-at-warehouse', $order), ['code' => $order->confirmation_code])->assertSessionHas('error');
        $this->assertSame('shipped', $order->fresh()->status);

        $order->update(['balance_status' => Order::BALANCE_PAID]);
        $this->actingAs($this->buyer, 'sanctum')->getJson("/api/v1/orders/{$order->id}")->assertOk()->assertJsonPath('order.confirmation_code', $order->confirmation_code);
    }

    public function test_code_is_hidden_before_arrival(): void
    {
        $this->product->update(['weight' => '400']);
        $order = Order::find($this->pickup($this->sea, 1)->assertCreated()->json('order_id'));
        $this->actingAs($this->admin)->post(route('admin.wholesale-orders.confirm', $order));
        app(AssoShipmentService::class)->advance($order, ['step' => 'handed_to_carrier'], $this->admin);

        $this->actingAs($this->buyer, 'sanctum')->getJson("/api/v1/orders/{$order->id}")->assertOk()->assertJsonMissingPath('order.confirmation_code');
    }

    public function test_wrong_codes_are_limited_per_order(): void
    {
        $order = $this->arrivedPickup();
        $wrong = $order->confirmation_code === '000000' ? '000001' : '000000';
        foreach (range(1, 5) as $i) {
            $this->actingAs($this->admin)->post(route('admin.wholesale-orders.collect-at-warehouse', $order), ['code' => $wrong]);
        }

        $this->actingAs($this->admin)->post(route('admin.wholesale-orders.collect-at-warehouse', $order), ['code' => $order->confirmation_code])
            ->assertSessionHas('error');
        $this->assertSame('shipped', $order->fresh()->status);
        RateLimiter::clear('warehouse-code:'.$order->id);
    }

    public function test_admin_product_dimensions_must_be_complete_and_in_centimeters(): void
    {
        $shop = $this->product->shop;
        $body = fn (array $extra) => $extra + [
            'name' => 'Voiture', 'description' => 'Berline', 'price_type' => 'fixed', 'price' => 1000, 'stock' => 1, 'status' => 'active', 'type' => 'article', 'weight' => 1200,
            'category_id' => $this->product->category_id, 'shop_id' => $shop->id, 'origin_country' => 'CN',
        ];

        $this->actingAs($this->admin)->put(route('admin.products.update', $this->product), $body(['length_cm' => 450]))
            ->assertSessionHasErrors('length_cm');
        $this->actingAs($this->admin)->put(route('admin.products.update', $this->product), $body(['length_cm' => 4.5, 'width_cm' => 1.8, 'height_cm' => 1.5]))
            ->assertSessionHasErrors('length_cm');
        $this->actingAs($this->admin)->put(route('admin.products.update', $this->product), $body(['length_cm' => 450, 'width_cm' => 180, 'height_cm' => 150]))
            ->assertSessionHasNoErrors();
        $this->assertEquals(450, $this->product->refresh()->length_cm);

        // Dimensions vides : acceptées, le bloc reste mis en avant.
        $this->actingAs($this->admin)->put(route('admin.products.update', $this->product), $body([]))->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->get(route('admin.products.edit', $this->product))->assertOk()
            ->assertSee('Dimensions du colis')->assertSee('Nouveau')->assertSee('data-add-dimension', false)->assertSee('Longueur (cm)');
    }
}
