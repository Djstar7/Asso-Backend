<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\DeliveryCityGrid;
use App\Models\DeliveryRoute;
use App\Models\ImportCountry;
use App\Models\ImportShippingOption;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductPriceTier;
use App\Models\Setting;
use App\Models\Shop;
use App\Models\User;
use App\Models\WalletBalance;
use App\Services\CommissionService;
use App\Services\FcmService;
use App\Services\FirebaseMessagingService;
use App\Support\ImportHub;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Import en gros : Chine / Dubaï / Turquie → entrepôt ASSO de Douala, puis SOLEX
 * jusqu'au client. Le client paie les deux trajets à la commande.
 */
class WholesaleSolexDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private Shop $hub;
    private DeliveryCityGrid $grid;
    private Product $product;
    private ProductPriceTier $tier;
    private ImportShippingOption $sea;
    private User $buyer;

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

        // Entrepôt à Akwa-Nord (Zone 2 de la grille SOLEX Douala).
        $this->hub = ImportHub::ensureShop();
        $this->hub->update(['address' => 'Rue 1.234, Akwa-Nord, Douala']);

        // Le fournisseur est en Chine : sa boutique ne compte pas pour la livraison.
        $supplier = User::factory()->create();
        $supplierShop = Shop::create([
            'user_id' => $supplier->id, 'name' => 'Usine Guangzhou', 'slug' => 'gz-' . uniqid(),
            'status' => 'active', 'city' => 'Guangzhou', 'country' => 'Chine',
        ]);
        ImportCountry::create(['code' => 'CN', 'name' => 'Chine', 'is_active' => true]);
        $this->product = Product::create([
            'user_id' => $supplier->id, 'shop_id' => $supplierShop->id,
            'category_id' => Category::create(['name' => 'Maison', 'slug' => 'maison-' . uniqid()])->id,
            'name' => 'Ventilateur', 'slug' => 'ventilo-' . uniqid(), 'price' => 1000, 'currency' => 'XAF',
            'stock' => 0, 'status' => 'active', 'is_wholesale' => true, 'origin_country' => 'CN', 'weight' => '0.2',
        ]);
        $this->tier = ProductPriceTier::create([
            'product_id' => $this->product->id, 'label' => 'Carton de 50', 'unit_price' => 1000,
            'currency' => 'XAF', 'min_quantity' => 50, 'is_active' => true,
        ]);
        $this->sea = ImportShippingOption::create([
            'country_code' => 'CN', 'mode' => 'sea', 'rate_type' => 'per_kg',
            'rate_amount' => 1500, 'currency' => 'XAF', 'lead_time_days' => 45, 'is_active' => true,
        ]);

        $this->buyer = User::factory()->create();
        WalletBalance::updateOrCreate(
            ['user_id' => $this->buyer->id, 'currency' => 'XAF'],
            ['balance' => 1_000_000, 'locked_balance' => 0]
        );
    }

    private function quote(array $query): array
    {
        $items = http_build_query(['items' => [['product_id' => $this->product->id, 'quantity' => 50]]]);

        return $this->getJson('/api/v1/delivery/partners?' . $items . '&' . http_build_query($query))->assertOk()->json();
    }

    private function order(array $delivery)
    {
        return $this->actingAs($this->buyer, 'sanctum')->postJson('/api/v1/import/orders', [
            'items' => [['product_id' => $this->product->id, 'price_tier_id' => $this->tier->id, 'quantity' => 50]],
            'shipping_option_id' => $this->sea->id,
            'payment_mode' => 'wallet',
            'customer_phone' => '237670000001',
        ] + $delivery);
    }

    public function test_attaching_wholesale_products_moves_them_to_the_douala_shop(): void
    {
        $this->assertSame(1, ImportHub::attachWholesaleProducts($this->hub));
        $this->assertSame($this->hub->id, $this->product->fresh()->shop_id);
        $this->assertSame($this->hub->user_id, $this->product->fresh()->user_id);
        $this->assertStringStartsWith('Zone 2', ImportHub::deliveryZoneLabel($this->hub->fresh()));
    }

    public function test_solex_quote_leaves_from_douala_whatever_the_supplier_country(): void
    {
        $response = $this->quote(['city' => 'Douala', 'quarter' => 'Makèpè']);

        $this->assertSame('Douala', $response['quote']['origin']['city']);
        $this->assertSame('CM', $response['quote']['origin']['country']);
        // 50 × 0,2 kg = 10 kg, Zone 2 → Zone 3 en moto : 1 500 HT + TVA 289.
        $moto = collect($response['partners'])->firstWhere('grid_id', $this->grid->id);
        $this->assertSame('moto', $moto['vehicle']);
        $this->assertEquals(1789, $moto['delivery_price']);
    }

    public function test_douala_buyer_pays_import_and_solex_then_a_solex_courier_delivers_from_the_hub(): void
    {
        $orderId = $this->order([
            'delivery_company_id' => $this->grid->deliverer_company_id,
            'delivery_grid_id' => $this->grid->id,
            'delivery_vehicle' => 'moto',
            'delivery_quarter' => 'Makèpè',
            'delivery_city' => 'Douala',
            'delivery_address' => 'Makèpè, Douala',
        ])->assertCreated()->json('order_id');

        $order = Order::findOrFail($orderId);
        // Produits 50 000 + bateau 10 kg × 1 500 = 15 000 + SOLEX 1 789.
        $this->assertEquals(15000, (float) $order->import_shipping_fee);
        $this->assertEquals(16789, (float) $order->delivery_fee);
        $this->assertEquals(66789, (float) $order->total);
        // SOLEX est payé de sa course seulement, pas du trajet depuis la Chine.
        $this->assertEquals(1789, (float) $order->base_delivery_price);
        $this->assertSame($this->grid->deliverer_company_id, $order->delivery_company_id);
        $this->assertSame('arrived_hub', $order->lastMileStep());

        $buyerView = $this->actingAs($this->buyer, 'sanctum')->getJson("/api/v1/orders/{$orderId}")->json('order');
        $this->assertSame('Expédition Chine → Douala (Bateau)', $buyerView['delivery']['import_leg']['label']);
        $this->assertEquals(15000, $buyerView['import_shipping_fee']);

        // Coursier SOLEX synchronisé.
        $courier = User::factory()->create(['role' => 'livreur']);
        $code = \App\Models\DelivererSyncCode::create([
            'company_id' => $this->grid->deliverer_company_id, 'sync_code' => 'SOLX-0000-0002',
            'sent_via' => 'email', 'sent_at' => now(), 'expires_at' => now()->addDays(30),
        ]);
        \App\Models\DelivererCodeSync::create([
            'user_id' => $courier->id, 'company_id' => $this->grid->deliverer_company_id,
            'sync_code_id' => $code->id, 'synced_at' => now(), 'is_active' => true, 'is_banned' => false,
        ]);

        // ASSO (propriétaire de l'entrepôt) valide, expédie depuis la Chine, puis reçoit à Douala.
        ImportHub::attachWholesaleProducts($this->hub);
        $order->items()->update(['seller_id' => $this->hub->user_id]);
        $asso = $this->hub->user;
        $this->actingAs($asso, 'sanctum')->postJson("/api/v1/vendor/orders/{$orderId}/validate")->assertOk();
        $this->actingAs($asso, 'sanctum')->postJson("/api/v1/vendor/orders/{$orderId}/hand-to-carrier", ['carrier_tracking_number' => 'MSK-1'])->assertOk();
        $this->actingAs($asso, 'sanctum')->postJson("/api/v1/vendor/orders/{$orderId}/tracking", ['step' => 'customs'])->assertOk();
        $this->actingAs($courier, 'sanctum')->getJson('/api/v1/delivery/pending')->assertJsonCount(0, 'requests');

        $this->actingAs($asso, 'sanctum')->postJson("/api/v1/vendor/orders/{$orderId}/tracking", ['step' => 'arrived_hub'])->assertOk();
        $request = $this->actingAs($courier, 'sanctum')->getJson('/api/v1/delivery/pending')
            ->assertJsonCount(1, 'requests')->json('requests.0');
        // Le coursier retire le colis à l'entrepôt ASSO, pas dans une agence SOLEX.
        $this->assertSame('shop', $request['pickup']['kind']);
        $this->assertSame(ImportHub::NAME, $request['pickup']['name']);

        // L'acheteur ne confirme pas lui-même : il donne son code au coursier.
        $buyerView = $this->actingAs($this->buyer, 'sanctum')->getJson("/api/v1/orders/{$orderId}")->json('order');
        $this->assertFalse($buyerView['delivery']['can_confirm_reception']);
        $this->assertSame("Arrivé à l'entrepôt ASSO de Douala", $buyerView['delivery']['tracking_status_label']);

        $this->actingAs($courier, 'sanctum')->postJson("/api/v1/delivery/{$orderId}/accept")->assertOk();
        $this->actingAs($courier, 'sanctum')
            ->postJson("/api/v1/delivery/{$orderId}/complete", ['confirmation_code' => $buyerView['confirmation_code']])
            ->assertOk();
        $this->assertSame('delivered', $order->fresh()->status);
    }

    public function test_buyer_outside_douala_gets_the_solex_route_from_douala(): void
    {
        $route = DeliveryRoute::where('destination_city', 'Yaoundé')->firstOrFail();
        $offer = collect($this->quote(['city' => 'Yaoundé'])['partners'])->firstWhere('route_id', $route->id);
        $this->assertNotNull($offer);

        $orderId = $this->order([
            'delivery_company_id' => $route->deliverer_company_id,
            'delivery_route_id' => $route->id,
            'delivery_city' => 'Yaoundé',
            'delivery_address' => 'Mvan, Yaoundé',
        ])->assertCreated()->json('order_id');

        $order = Order::findOrFail($orderId);
        $this->assertEquals(15000 + $offer['delivery_price'], (float) $order->delivery_fee);
        $this->assertSame($route->id, $order->delivery_route_id);
        // Retrait en agence SOLEX de Yaoundé : l'acheteur confirme lui-même la réception.
        $this->assertNull($order->lastMileStep());
    }

    public function test_tier_weight_counts_for_each_ordered_unit(): void
    {
        // Un « carton » pèse 1 kg : il prime sur les 0,2 kg de la fiche.
        $this->tier->update(['weight_kg' => 1]);

        $items = http_build_query(['items' => [[
            'product_id' => $this->product->id, 'quantity' => 50, 'price_tier_id' => $this->tier->id,
        ]]]);
        $this->getJson("/api/v1/delivery/partners?{$items}&city=Yaoundé")->assertJsonPath('quote.weight_kg', 50);
        $this->getJson('/api/v1/import/products/' . $this->product->id)->assertJsonPath('product.price_tiers.0.weight_kg', 1);

        $route = DeliveryRoute::where('destination_city', 'Yaoundé')->firstOrFail();
        $orderId = $this->order([
            'delivery_company_id' => $route->deliverer_company_id,
            'delivery_route_id' => $route->id,
            'delivery_city' => 'Yaoundé',
            'delivery_address' => 'Mvan, Yaoundé',
        ])->assertCreated()->json('order_id');

        $order = Order::findOrFail($orderId);
        // Bateau : 50 kg × 1 500.
        $this->assertEquals(75000, (float) $order->import_shipping_fee);
        $this->assertEquals(50, $order->shipping_weight_kg);
    }

    public function test_order_without_solex_delivery_is_refused(): void
    {
        $this->order([])->assertStatus(422)->assertJsonValidationErrors(['delivery_company_id', 'delivery_address']);
        $this->assertSame(0, Order::count());
    }
}
