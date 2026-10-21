<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\DelivererCompany;
use App\Models\DeliveryPricelist;
use App\Models\DeliveryRoute;
use App\Models\DeliveryZone;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Shop;
use App\Models\User;
use App\Models\WalletBalance;
use App\Services\CommissionService;
use App\Services\FcmService;
use App\Services\FirebaseMessagingService;
use App\Support\WeightGrid;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P4 — Livraison et partenaires logistiques : poids réel, grilles SOLEX (HT + TVA),
 * blocage sans poids, rayon réglable, suivi transporteur jusqu'à la réception.
 */
class DeliveryPartnersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        CommissionService::flush();
        $this->mock(FirebaseMessagingService::class, fn ($mock) => $mock->shouldReceive('sendToUser')->andReturn([]));
        $this->mock(FcmService::class, fn ($mock) => $mock->shouldIgnoreMissing());
        Setting::set('delivery_vat_rate', '19.25', 'string', 'delivery');
    }

    /** SOLEX Douala ↔ Yaoundé (plis 2 500 / colis 4 000 / 150 F le kg suppl.), grille HT. */
    private function solex(): DeliveryRoute
    {
        $solex = DelivererCompany::create([
            'name' => 'SOLEX',
            'service_type' => DelivererCompany::SERVICE_INTERCITY,
            'service_mode' => DelivererCompany::MODE_AGENCY,
            'prices_exclude_vat' => true,
            'conditions' => "D'agence en agence.",
            'is_active' => true,
        ]);

        return DeliveryRoute::create([
            'deliverer_company_id' => $solex->id,
            'origin_country' => 'CM', 'origin_city' => 'Douala',
            'destination_country' => 'CM', 'destination_city' => 'Yaoundé',
            'bidirectional' => true,
            'pricing_data' => [
                'ranges' => [
                    ['min' => 0, 'max' => 2, 'price' => 2500, 'label' => 'Plis & paquets'],
                    ['min' => 2, 'max' => 10, 'price' => 4000, 'label' => 'Colis'],
                ],
                'extra_per_kg' => 150,
            ],
            'lead_time' => '24 h',
            'is_active' => true,
        ]);
    }

    private function product(?string $weight, string $city = 'Douala'): Product
    {
        $seller = User::factory()->create();
        $shop = Shop::create([
            'user_id' => $seller->id, 'name' => 'Boutique', 'slug' => 'b-' . uniqid(),
            'status' => 'active', 'city' => $city, 'country' => 'Cameroun',
        ]);

        return Product::create([
            'user_id' => $seller->id, 'shop_id' => $shop->id,
            'category_id' => Category::create(['name' => 'Mode', 'slug' => 'mode-' . uniqid()])->id,
            'name' => 'Sac', 'slug' => 'sac-' . uniqid(), 'price' => 10000, 'currency' => 'XAF',
            'stock' => 20, 'status' => 'active', 'type' => 'article', 'weight' => $weight,
        ]);
    }

    public function test_weight_grid_applies_tiers_and_extra_kg(): void
    {
        $grid = ['ranges' => [['min' => 0, 'max' => 2, 'price' => 2500], ['min' => 2, 'max' => 10, 'price' => 4000]], 'extra_per_kg' => 150];

        $this->assertEquals(2500, WeightGrid::price($grid, 1.2)['price']);
        $this->assertEquals(4000, WeightGrid::price($grid, 10)['price']);
        // 12,3 kg = colis + 3 kg entamés × 150.
        $this->assertEquals(4000 + 3 * 150, WeightGrid::price($grid, 12.3)['price']);
        $this->assertNull(WeightGrid::price(['ranges' => $grid['ranges']], 12));
    }

    public function test_buyer_sees_solex_price_from_total_weight_with_vat_and_details(): void
    {
        $this->solex();
        $product = $this->product('3');

        $response = $this->getJson("/api/v1/delivery/partners?product_id={$product->id}&quantity=2&city=" . urlencode('Yaoundé, Cameroun'))
            ->assertOk();

        $partner = $response->json('partners.0');
        $this->assertSame('SOLEX', $partner['company_name']);
        $this->assertSame('intercity', $partner['service_type']);
        $this->assertSame('agency_to_agency', $partner['service_mode']);
        $this->assertSame('Douala ↔ Yaoundé', $partner['route_label']);
        $this->assertSame('24 h', $partner['lead_time']);
        // 2 × 3 kg = 6 kg → colis 4 000 HT + TVA 19,25 % (770) = 4 770.
        $this->assertEquals(6, $partner['breakdown']['weight_kg']);
        $this->assertEquals(4000, $partner['breakdown']['carrier_price_ht']);
        $this->assertEquals(770, $partner['breakdown']['vat_amount']);
        $this->assertEquals(4770, $partner['delivery_price']);
        $this->assertCount(2, $partner['price_grid']);

        // Vice versa : boutique à Yaoundé, acheteur à Douala.
        $reverse = $this->product('1', 'Yaoundé');
        $this->getJson("/api/v1/delivery/partners?product_id={$reverse->id}&city=Douala")
            ->assertJsonPath('partners.0.delivery_price', 2500 + 481);
    }

    public function test_product_without_weight_uses_default_weight_of_2_kg(): void
    {
        $route = $this->solex();
        $product = $this->product(null);

        // Sans poids : chiffré comme 2 kg (plis & paquets 2 500 HT + TVA 481), signalé dans le devis.
        $response = $this->getJson("/api/v1/delivery/partners?product_id={$product->id}&city=Yaoundé")->assertOk();
        $this->assertNull($response->json('quote.reason'));
        $this->assertEquals(2, $response->json('quote.default_weight_kg'));
        $this->assertSame(['« ' . $product->name . ' »'], $response->json('quote.missing_weight_products'));
        $this->assertEquals(2500 + 481, $response->json('partners.0.delivery_price'));

        // Poids par défaut réglable dans l'admin.
        \App\Models\Setting::set('delivery_default_weight_kg', 6, 'string', 'delivery');
        $this->getJson("/api/v1/delivery/partners?product_id={$product->id}&city=Yaoundé")
            ->assertJsonPath('partners.0.breakdown.weight_kg', 6);

        $client = User::factory()->create();
        WalletBalance::create(['user_id' => $client->id, 'currency' => 'XAF', 'balance' => 100000, 'locked_balance' => 0]);
        $this->actingAs($client, 'sanctum')->postJson('/api/v1/orders', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'delivery_company_id' => $route->deliverer_company_id,
            'delivery_route_id' => $route->id,
            'delivery_city' => 'Yaoundé',
            'payment_mode' => 'wallet', 'wallet_provider' => 'kpay',
            'customer_phone' => '237670000001',
        ])->assertSuccessful();
    }

    public function test_admin_product_form_saves_weight_of_local_article(): void
    {
        $product = $this->product(null);
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->put(route('admin.products.update', $product), [
            'shop_id' => $product->shop_id, 'category_id' => $product->category_id,
            'name' => $product->name, 'price_type' => 'fixed', 'price' => 320000,
            'type' => 'article', 'origin_country' => '', 'weight' => '0.4', 'stock' => 20, 'status' => 'active',
        ])->assertSessionHasNoErrors();

        $this->assertEquals(0.4, (float) $product->fresh()->weight);
    }

    public function test_local_zone_radius_is_configurable(): void
    {
        $company = DelivererCompany::create(['name' => 'Coursier', 'is_active' => true]);
        $zone = DeliveryZone::create([
            'deliverer_company_id' => $company->id, 'name' => 'Akwa', 'city' => null,
            'center_latitude' => 4.05, 'center_longitude' => 9.70, 'is_active' => true,
        ]);
        DeliveryPricelist::create([
            'delivery_zone_id' => $zone->id, 'pricing_type' => 'fixed',
            'pricing_data' => ['price' => 1000], 'asso_commission' => 0, 'is_active' => true,
        ]);
        $product = $this->product('1');

        // ~15 km du centre de la zone.
        $url = "/api/v1/delivery/partners?product_id={$product->id}&latitude=4.185&longitude=9.70&city=Douala";
        Setting::set('delivery_zone_radius_km', '10', 'string', 'delivery');
        $zone->update(['city' => 'Autre']);
        $this->getJson($url)->assertJsonCount(0, 'partners');

        Setting::set('delivery_zone_radius_km', '20', 'string', 'delivery');
        $this->getJson($url)->assertJsonPath('partners.0.company_name', 'Coursier');
    }

    public function test_carrier_order_is_tracked_from_order_to_buyer_reception(): void
    {
        $route = $this->solex();
        $product = $this->product('3');
        $seller = $product->user;
        $client = User::factory()->create();
        WalletBalance::create(['user_id' => $client->id, 'currency' => 'XAF', 'balance' => 100000, 'locked_balance' => 0]);

        $orderId = $this->actingAs($client, 'sanctum')->postJson('/api/v1/orders', [
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
            'delivery_company_id' => $route->deliverer_company_id,
            'delivery_route_id' => $route->id,
            'delivery_city' => 'Yaoundé',
            'payment_mode' => 'wallet', 'wallet_provider' => 'kpay',
            'customer_phone' => '237670000001',
        ])->assertCreated()->json('order_id');

        $order = Order::findOrFail($orderId);
        $this->assertSame(Order::DELIVERY_CARRIER, $order->delivery_mode);
        $this->assertEquals(6, $order->shipping_weight_kg);
        $this->assertEquals(4770, (float) $order->delivery_fee);
        $this->assertEquals(770, (float) $order->delivery_vat_amount);

        $this->actingAs($seller, 'sanctum')->postJson("/api/v1/vendor/orders/{$orderId}/validate")->assertOk();
        // Un transporteur ne s'assigne pas comme un livreur urbain.
        $this->actingAs($seller, 'sanctum')->postJson("/api/v1/vendor/orders/{$orderId}/assign-delivery", ['delivery_person_id' => $seller->id])
            ->assertStatus(422);

        $this->actingAs($seller, 'sanctum')
            ->postJson("/api/v1/vendor/orders/{$orderId}/hand-to-carrier", ['carrier_tracking_number' => 'SLX-001', 'location' => 'Agence Akwa'])
            ->assertOk()
            ->assertJsonPath('order.delivery.carrier_tracking_number', 'SLX-001');

        $this->actingAs($seller, 'sanctum')
            ->postJson("/api/v1/vendor/orders/{$orderId}/tracking", ['step' => 'ready_for_pickup'])
            ->assertUnprocessable();
        $admin = User::factory()->create(['role' => 'admin']);
        $agency = \App\Models\DeliveryAgency::create([
            'deliverer_company_id' => $route->deliverer_company_id, 'code' => 'SLX-MVAN',
            'name' => 'Agence Mvan', 'city' => 'Yaoundé', 'address' => 'Gare Mvan', 'phone' => '237670000001', 'is_active' => true,
        ]);
        $this->actingAs($admin, 'web')->post(route('admin.shipments.agency', $order), ['delivery_agency_id' => $agency->id])->assertSessionHasNoErrors();
        foreach (['arrived', 'ready_for_pickup'] as $step) {
            $this->post(route('admin.shipments.step', $order), ['step' => $step,
                'confirmation_contact' => 'Solex', 'confirmation_reference' => 'SLX-001', 'confirmation_channel' => 'phone'])->assertSessionHasNoErrors();
        }
        $detail = $this->actingAs($client, 'sanctum')->getJson("/api/v1/orders/{$orderId}")->assertOk();
        $this->assertFalse($detail->json('order.delivery.can_confirm_reception'));
        $this->assertNull($detail->json('order.confirmation_code'));
        $this->assertSame('Agence Mvan', $detail->json('order.delivery.pickup_agency.name'));
        $this->actingAs($client, 'sanctum')->postJson("/api/v1/orders/{$orderId}/confirm-reception")->assertUnprocessable();
        $this->actingAs($admin, 'web')->post(route('admin.shipments.step', $order), ['step' => 'collected_at_agency',
            'confirmation_contact' => 'Solex', 'confirmation_reference' => 'RETRAIT-001', 'confirmation_channel' => 'document'])->assertSessionHasNoErrors();
        $this->actingAs($client, 'sanctum')->postJson("/api/v1/orders/{$orderId}/confirm-reception")
            ->assertOk()->assertJsonPath('order.status', 'delivered')
            ->assertJsonPath('order.delivery.tracking_status', 'reception_confirmed');
    }

    public function test_international_route_must_arrive_in_cameroon(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $dhl = DelivererCompany::create(['name' => 'DHL', 'service_type' => 'international', 'is_active' => true]);

        $payload = [
            'origin_country' => 'CM', 'destination_country' => 'FR',
            'ranges' => [['min' => 0, 'max' => 5, 'price' => 30000]],
        ];
        $this->actingAs($admin)->post(route('admin.delivery-partners.routes.store', $dhl), $payload)
            ->assertSessionHasErrors('destination_country');

        $payload = ['origin_country' => 'CN', 'destination_country' => 'CM', 'bidirectional' => '1'] + $payload;
        $this->actingAs($admin)->post(route("admin.delivery-partners.routes.store", $dhl), $payload)->assertSessionHasNoErrors();
        $this->assertFalse($dhl->deliveryRoutes()->first()->bidirectional, "L'international n'est jamais « vice versa »");
    }

    public function test_solex_urban_zone_prices_by_weight_with_lead_time_and_home_delivery(): void
    {
        $route = $this->solex(); // SOLEX : d'agence en agence en interurbain
        $zone = DeliveryZone::create([
            'deliverer_company_id' => $route->deliverer_company_id, 'name' => 'Douala centre', 'city' => 'Douala',
            'center_latitude' => 4.05, 'center_longitude' => 9.70, 'is_active' => true,
        ]);
        DeliveryPricelist::create([
            'delivery_zone_id' => $zone->id, 'pricing_type' => 'volumetric_weight',
            'pricing_data' => ['ranges' => [['min' => 0, 'max' => 5, 'price' => 1500]], 'extra_per_kg' => 200],
            'asso_commission' => 0, 'lead_time' => '24 h', 'is_active' => true,
        ]);
        $product = $this->product('2');

        // 4 × 2 kg = 8 kg → 1 500 + 3 kg × 200 = 2 100 HT, + TVA 19,25 % (404) = 2 504.
        $partner = $this->getJson("/api/v1/delivery/partners?product_id={$product->id}&quantity=4&city=Douala")
            ->assertOk()->json('partners.0');
        $this->assertSame('SOLEX', $partner['company_name']);
        $this->assertSame('local', $partner['service_type']);
        $this->assertSame('door_to_door', $partner['service_mode'], 'Le coursier SOLEX livre à domicile en ville');
        $this->assertSame('24 h', $partner['lead_time']);
        $this->assertEquals(3, $partner['breakdown']['extra_kg']);
        $this->assertEquals(2504, $partner['delivery_price']);

        // Code de synchronisation pour le coursier SOLEX.
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->post(route('admin.deliverers.sync-code', $route->deliverer_company_id))->assertSessionHas('success');
        $this->assertDatabaseHas('deliverer_sync_codes', ['company_id' => $route->deliverer_company_id, 'is_used' => false]);
    }

    public function test_solex_intercity_home_delivery_adds_destination_zone_and_asso_records_handover_with_code(): void
    {
        $route = $this->solex(); // Douala ↔ Yaoundé, d'agence à agence
        $zone = DeliveryZone::create([
            'deliverer_company_id' => $route->deliverer_company_id, 'name' => 'Yaoundé centre', 'city' => 'Yaoundé',
            'center_latitude' => 3.87, 'center_longitude' => 11.52, 'is_active' => true,
        ]);
        DeliveryPricelist::create([
            'delivery_zone_id' => $zone->id, 'pricing_type' => 'volumetric_weight',
            'pricing_data' => ['ranges' => [['min' => 0, 'max' => 10, 'price' => 1000]], 'extra_per_kg' => 0],
            'asso_commission' => 0, 'lead_time' => '24 h', 'is_active' => true,
        ]);
        $product = $this->product('3');

        $partners = collect($this->getJson("/api/v1/delivery/partners?product_id={$product->id}&quantity=2&city=Yaoundé")
            ->assertOk()->json('partners'))->keyBy('delivery_option');

        // Retrait en agence : trajet seul, 4 000 HT + 770 = 4 770.
        $this->assertEquals(4770, $partners['agency_pickup']['delivery_price']);
        $this->assertNull($partners['agency_pickup']['zone_id']);
        // À domicile : (4 000 + 1 000) HT + TVA 963 = 5 963, un seul prix, deux volets en détail.
        $home = $partners['home_delivery'];
        $this->assertEquals(5963, $home['delivery_price']);
        $this->assertSame('door_to_door', $home['service_mode']);
        $this->assertSame('24 h + 24 h', $home['lead_time']);
        $this->assertSame([4000.0, 1000.0], array_map('floatval', array_column($home['breakdown']['legs'], 'price')));

        // Commande à domicile.
        $client = User::factory()->create();
        WalletBalance::create(['user_id' => $client->id, 'currency' => 'XAF', 'balance' => 100000, 'locked_balance' => 0]);
        $orderId = $this->actingAs($client, 'sanctum')->postJson('/api/v1/orders', [
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
            'delivery_company_id' => $route->deliverer_company_id,
            'delivery_route_id' => $route->id,
            'delivery_zone_id' => $zone->id,
            'delivery_city' => 'Yaoundé',
            'payment_mode' => 'wallet', 'wallet_provider' => 'kpay',
            'customer_phone' => '237670000001',
        ])->assertCreated()->json('order_id');
        $this->assertEquals(5963, (float) Order::find($orderId)->delivery_fee);

        // Coursier SOLEX synchronisé.
        $courier = User::factory()->create(['role' => 'livreur']);
        $code = \App\Models\DelivererSyncCode::create([
            'company_id' => $route->deliverer_company_id, 'sync_code' => 'SOLX-0000-0001',
            'sent_via' => 'email', 'sent_at' => now(), 'expires_at' => now()->addDays(30),
        ]);
        \App\Models\DelivererCodeSync::create([
            'user_id' => $courier->id, 'company_id' => $route->deliverer_company_id,
            'sync_code_id' => $code->id, 'synced_at' => now(), 'is_active' => true, 'is_banned' => false,
        ]);

        $seller = $product->user;
        $this->actingAs($seller, 'sanctum')->postJson("/api/v1/vendor/orders/{$orderId}/validate")->assertOk();
        $this->actingAs($seller, 'sanctum')->postJson("/api/v1/vendor/orders/{$orderId}/hand-to-carrier", ['carrier_tracking_number' => 'SLX-9'])->assertOk();

        // Le partenaire n'intervient jamais dans l'app : ASSO enregistre ses confirmations.
        $this->actingAs($courier, 'sanctum')->getJson('/api/v1/delivery/pending')->assertJsonCount(0, 'requests');
        $admin = User::factory()->create(['role' => 'admin']);
        foreach (['arrived', 'out_for_delivery', 'delivered'] as $step) {
            $this->actingAs($admin, 'web')->post(route('admin.shipments.step', $orderId), ['step' => $step,
                'confirmation_contact' => 'Solex', 'confirmation_reference' => 'SLX-9', 'confirmation_channel' => 'phone'])->assertSessionHasNoErrors();
        }
        $this->actingAs($courier, 'sanctum')->getJson('/api/v1/delivery/pending')->assertJsonCount(0, 'requests');
        $this->postJson("/api/v1/delivery/{$orderId}/accept")->assertStatus(422);
        $buyerView = $this->actingAs($client, 'sanctum')->getJson("/api/v1/orders/{$orderId}")->json('order');
        $this->assertTrue($buyerView['delivery']['can_confirm_reception']);
        $this->assertEmpty($buyerView['confirmation_code'] ?? null);
        $this->postJson("/api/v1/orders/{$orderId}/confirm-reception")->assertOk();
        $this->assertSame('delivered', Order::find($orderId)->status);
        $this->assertSame(
            ['pending', 'confirmed', 'handed_to_carrier', 'arrived', 'out_for_delivery', 'delivered', 'reception_confirmed'],
            Order::find($orderId)->trackingEvents->pluck('step')->all()
        );
    }

    /** SOLEX Douala : grille zone à zone du seeder (moto, tricycle, 600 kg, 1 t). */
    private function solexDouala(): \App\Models\DeliveryCityGrid
    {
        (new \Database\Seeders\DeliveryPartnersSeeder())->run();

        return \App\Models\DeliveryCityGrid::where('city', 'Douala')->firstOrFail();
    }

    public function test_solex_douala_picks_the_vehicle_from_parcel_weight(): void
    {
        $grid = $this->solexDouala();
        $product = $this->product('5'); // boutique « Douala » sans quartier
        $product->shop->update(['address' => 'Rue 1.234, Akwa-Nord, Douala']); // Zone 2

        // Sans position ni quartier : l'app demande de placer le repère.
        $quote = $this->getJson("/api/v1/delivery/partners?product_id={$product->id}&city=Douala")->assertOk()->json('quote');
        $this->assertTrue($quote['city_grid']['quarter_required']);

        // Makèpè (Zone 3), 2 × 5 kg = 10 kg : une seule offre SOLEX, en moto (≤ 30 kg).
        $offers = collect($this->getJson("/api/v1/delivery/partners?product_id={$product->id}&quantity=2&city=Douala&latitude=4.0829&longitude=9.7561")
            ->assertOk()->json('partners'))->where('grid_id', $grid->id)->values();
        $this->assertCount(1, $offers);
        $moto = $offers[0];
        $this->assertSame('moto', $moto['vehicle']);
        $this->assertEquals(1789, $moto['delivery_price']); // 1 500 HT + TVA 289
        $this->assertSame('1 h à 3 h', $moto['lead_time']);
        $this->assertSame('door_to_door', $moto['service_mode']);
        $this->assertStringContainsString('Zone 2', $moto['route_label']);
        $this->assertStringContainsString('Zone 3', $moto['route_label']);
        $this->assertStringNotContainsString('Makèpè', $moto['route_label']);

        // Commission ASSO en % du prix HT : 10 % de 1 500 = 150.
        \App\Models\Setting::set('delivery_commission_rate', '10', 'string', 'commissions');
        $this->getJson("/api/v1/delivery/partners?product_id={$product->id}&quantity=2&city=Douala&quarter=Makepe")
            ->assertJsonPath('partners.0.asso_commission', 150)
            ->assertJsonPath('partners.0.delivery_price', 1939);
        \App\Models\Setting::set('delivery_commission_rate', '0', 'string', 'commissions');

        // 40 kg : au-delà de la moto (30 kg), le tricycle (≤ 300 kg) est choisi.
        $heavy = collect($this->getJson("/api/v1/delivery/partners?product_id={$product->id}&quantity=8&city=Douala&quarter=Makepe")
            ->json('partners'))->where('grid_id', $grid->id)->pluck('vehicle')->all();
        $this->assertSame(['tricycle'], $heavy);

        // 400 kg : camionnette 600 kg.
        $this->assertSame(['600kg'], collect($this->getJson("/api/v1/delivery/partners?product_id={$product->id}&quantity=80&city=Douala&quarter=Makepe")
            ->json('partners'))->where('grid_id', $grid->id)->pluck('vehicle')->all());

        // Commande en tricycle : prix recalculé côté serveur, livrée par un coursier SOLEX.
        $client = User::factory()->create();
        WalletBalance::create(['user_id' => $client->id, 'currency' => 'XAF', 'balance' => 100000, 'locked_balance' => 0]);
        $orderId = $this->actingAs($client, 'sanctum')->postJson('/api/v1/orders', [
            'items' => [['product_id' => $product->id, 'quantity' => 8]],
            'delivery_company_id' => $grid->deliverer_company_id,
            'delivery_grid_id' => $grid->id,
            'delivery_vehicle' => 'tricycle',
            'delivery_quarter' => 'Makèpè',
            'delivery_city' => 'Douala',
            'payment_mode' => 'wallet', 'wallet_provider' => 'kpay',
            'customer_phone' => '237670000001',
        ])->assertCreated()->json('order_id');

        $order = Order::find($orderId);
        $this->assertEquals(3578, (float) $order->delivery_fee);
        $this->assertSame('tricycle', $order->delivery_vehicle);
        $this->assertSame(Order::DELIVERY_LOCAL, $order->delivery_mode);
        $this->assertSame('Tricycle', $this->actingAs($client, 'sanctum')->getJson("/api/v1/orders/{$orderId}")->json('order.delivery.vehicle_label'));
    }

    public function test_shop_and_buyer_are_placed_in_their_zone_from_map_position(): void
    {
        $grid = $this->solexDouala();
        $product = $this->product('2');

        // Boutique placée sur sa carte à Akwa-Nord (Zone 2) : quartier déduit et affiché.
        $product->shop->update(['latitude' => 4.0642, 'longitude' => 9.7198]);
        $this->assertSame('Akwa-Nord', $product->shop->fresh()->quarter);
        $this->assertSame('Akwa-Nord, Douala, Cameroun', $product->shop->fresh()->location_label);

        // Acheteur géolocalisé à Makèpè (Zone 3), sans choisir de quartier.
        $response = $this->getJson("/api/v1/delivery/partners?product_id={$product->id}&city=Douala&latitude=4.0829&longitude=9.7561")->assertOk();
        $this->assertSame('Makèpè', $response->json('quote.city_grid.detected_quarter'));
        $moto = collect($response->json('partners'))->firstWhere('vehicle', 'moto');
        $this->assertEquals(1789, $moto['delivery_price']); // Zone 2 → Zone 3 : 1 500 HT
    }

    public function test_admin_can_delete_urban_delivery_unless_orders_are_in_progress(): void
    {
        $grid = $this->solexDouala();
        $admin = User::factory()->create(['role' => 'admin']);
        $product = $this->product('2');
        $product->shop->update(['latitude' => 4.0642, 'longitude' => 9.7198]);
        $this->assertSame('Akwa-Nord', $product->shop->fresh()->quarter);

        // Commande en cours sur la grille : suppression refusée.
        $order = Order::create([
            'user_id' => User::factory()->create()->id, 'status' => 'confirmed',
            'subtotal' => 1000, 'total' => 1000, 'delivery_fee' => 0,
            'payment_method' => 'wallet_kpay', 'payment_status' => 'paid',
            'delivery_city_grid_id' => $grid->id,
        ]);
        $this->actingAs($admin)->delete(route('admin.delivery-partners.city-grids.destroy', [$grid->deliverer_company_id, $grid]))
            ->assertSessionHasErrors('grid');
        $this->assertModelExists($grid);

        // Commande livrée : la ville peut être supprimée, la boutique perd son quartier.
        $order->update(['status' => 'delivered']);
        $this->actingAs($admin)->delete(route('admin.delivery-partners.city-grids.destroy', [$grid->deliverer_company_id, $grid]))
            ->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->assertModelMissing($grid);
        $this->assertNull($product->shop->fresh()->quarter);
        $this->assertNull($order->fresh()->delivery_city_grid_id);
    }

    public function test_coverage_shows_who_delivers_at_a_map_position(): void
    {
        $this->solexDouala();

        // Makèpè (Douala) : SOLEX livre à domicile, Zone 3 ; quartiers colorés et agence SOLEX.
        $coverage = $this->getJson('/api/v1/delivery/coverage?latitude=4.0829&longitude=9.7561')->assertOk()->json('coverage');
        $this->assertTrue($coverage['served']);
        $this->assertSame('SOLEX', $coverage['served_by'][0]['company_name']);
        $this->assertSame(3, $coverage['served_by'][0]['zone']);
        $this->assertSame('Makèpè', $coverage['served_by'][0]['quarter']);
        $this->assertNotEmpty($coverage['quarters']);
        $this->assertSame('Douala', $coverage['agencies'][0]['city']);

        // Yaoundé : pas de livraison à domicile, retrait à l'agence SOLEX (trajet vers Douala).
        $coverage = $this->getJson('/api/v1/delivery/coverage?latitude=3.87&longitude=11.51')->json('coverage');
        $this->assertFalse($coverage['served']);
        $this->assertSame('Yaoundé', $coverage['city']);
        $this->assertSame('SOLEX', $coverage['agencies'][0]['company_name']);
        $this->assertSame('Douala', $coverage['agencies'][0]['destinations'][0]['city']);

        // Loin de tout : rien.
        $coverage = $this->getJson('/api/v1/delivery/coverage?latitude=6.5&longitude=12.0')->json('coverage');
        $this->assertFalse($coverage['served']);
        $this->assertSame([], $coverage['agencies']);

        $this->getJson('/api/v1/delivery/coverage')->assertStatus(422);
    }

    public function test_map_cart_coverage_matches_quotes_instead_of_general_coverage(): void
    {
        $this->solexDouala();
        $product = $this->product('2');
        // La boutique n'a ni quartier ni coordonnées : aucun prix zone à zone calculable.
        $this->getJson('/api/v1/delivery/coverage?latitude=4.0829&longitude=9.7561')
            ->assertJsonPath('coverage.served', true);
        $params = ['latitude' => 4.0829, 'longitude' => 9.7561,
            'items' => [['product_id' => $product->id, 'quantity' => 1]]];
        $this->getJson('/api/v1/delivery/coverage?' . http_build_query($params))
            ->assertOk()->assertJsonPath('coverage.served', false)
            ->assertJsonCount(0, 'coverage.served_by');

        $product->shop->update(['latitude' => 4.0642, 'longitude' => 9.7198]);
        $this->getJson('/api/v1/delivery/coverage?' . http_build_query($params))
            ->assertOk()->assertJsonPath('coverage.served', true)
            ->assertJsonPath('coverage.served_by.0.company_name', 'SOLEX');
        $this->getJson('/api/v1/delivery/partners?' . http_build_query([
            'product_id' => $product->id, 'quantity' => 1,
            'latitude' => 4.0829, 'longitude' => 9.7561,
            // Une ancienne ville reconnue ne doit pas supplanter le point choisi.
            'city' => 'Yaoundé',
        ]))->assertOk()->assertJsonPath('quote.destination.city', 'Douala')
            ->assertJsonPath('partners.0.company_name', 'SOLEX');
    }

    public function test_gps_only_address_uses_nearest_city_for_intercity_routes(): void
    {
        $this->solex(); // Douala ↔ Yaoundé
        $product = $this->product('2');

        // Adresse sans nom de ville (géocodage indisponible) : la position suffit.
        $response = $this->getJson('/api/v1/delivery/partners?' . http_build_query([
            'product_id' => $product->id,
            'city' => 'Position GPS (3.8700, 11.5100)',
            'latitude' => 3.87, 'longitude' => 11.51,
        ]))->assertOk();

        $this->assertSame('Yaoundé', $response->json('quote.destination.city'));
        $this->assertSame('SOLEX', $response->json('partners.0.company_name'));
        $this->assertSame('Douala ↔ Yaoundé', $response->json('partners.0.route_label'));
    }
}
