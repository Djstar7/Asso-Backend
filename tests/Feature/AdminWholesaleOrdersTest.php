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
use App\Models\User;
use App\Models\WalletBalance;
use App\Services\CommissionService;
use App\Services\FcmService;
use App\Services\FirebaseMessagingService;
use App\Support\ImportHub;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Admin « Commandes en gros » : ASSO valide ou refuse, suit l'import jusqu'à
 * Douala, puis la livraison SOLEX.
 */
class AdminWholesaleOrdersTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $buyer;
    private DeliveryCityGrid $grid;
    private Product $product;
    private ProductPriceTier $tier;
    private ImportShippingOption $sea;

    protected function setUp(): void
    {
        parent::setUp();
        CommissionService::flush();
        $this->mock(FirebaseMessagingService::class, fn ($mock) => $mock->shouldReceive('sendToUser')->andReturn([]));
        $this->mock(FcmService::class, fn ($mock) => $mock->shouldIgnoreMissing());
        Setting::set('delivery_vat_rate', '19.25', 'string', 'delivery');

        $this->admin = User::factory()->create(['email' => 'admin@asso.com', 'role' => 'admin']);
        (new \Database\Seeders\DeliveryPartnersSeeder())->run();
        $this->grid = DeliveryCityGrid::where('city', 'Douala')->firstOrFail();
        $hub = ImportHub::ensureShop();
        $hub->update(['address' => 'Rue 1.234, Akwa-Nord, Douala']);

        ImportCountry::create(['code' => 'CN', 'name' => 'Chine', 'flag' => '🇨🇳', 'is_active' => true]);
        $this->product = Product::create([
            'user_id' => $hub->user_id, 'shop_id' => $hub->id,
            'category_id' => Category::create(['name' => 'Maison', 'slug' => 'maison-' . uniqid()])->id,
            'name' => 'Ventilateur de table', 'slug' => 'ventilo-' . uniqid(), 'price' => 1000, 'currency' => 'XAF',
            'stock' => 0, 'status' => 'active', 'is_wholesale' => true, 'origin_country' => 'CN', 'weight' => '0.2',
        ]);
        $this->tier = ProductPriceTier::create([
            'product_id' => $this->product->id, 'label' => 'Carton de 50', 'unit_price' => 1000,
            'currency' => 'XAF', 'min_quantity' => 50, 'is_active' => true,
        ]);
        $this->sea = ImportShippingOption::create([
            'country_code' => 'CN', 'mode' => 'sea', 'rate_type' => 'per_kg', 'carrier' => 'Maersk',
            'rate_amount' => 1500, 'currency' => 'XAF', 'lead_time_days' => 45, 'is_active' => true,
        ]);

        $this->buyer = User::factory()->create(['first_name' => 'Awa', 'last_name' => 'Ngono']);
        WalletBalance::updateOrCreate(
            ['user_id' => $this->buyer->id, 'currency' => 'XAF'],
            ['balance' => 1_000_000, 'locked_balance' => 0]
        );
    }

    /** Commande payée au wallet, livrée par SOLEX à Makèpè (Douala). */
    private function order(): Order
    {
        $id = $this->actingAs($this->buyer, 'sanctum')->postJson('/api/v1/import/orders', [
            'items' => [['product_id' => $this->product->id, 'price_tier_id' => $this->tier->id, 'quantity' => 50]],
            'shipping_option_id' => $this->sea->id,
            'payment_mode' => 'wallet',
            'delivery_company_id' => $this->grid->deliverer_company_id,
            'delivery_grid_id' => $this->grid->id,
            'delivery_vehicle' => 'moto',
            'delivery_quarter' => 'Makèpè',
            'delivery_city' => 'Douala',
            'delivery_address' => 'Makèpè, Douala',
            'customer_phone' => '237670000001',
        ])->assertCreated()->json('order_id');

        return Order::findOrFail($id);
    }

    public function test_list_shows_orders_to_validate_with_filters(): void
    {
        $order = $this->order();

        $this->actingAs($this->admin)->get('/admin/wholesale-orders')
            ->assertOk()
            ->assertSee('Commandes en gros')
            ->assertSee($order->order_number)
            ->assertSee('Awa Ngono')
            ->assertSee('À valider');

        $this->actingAs($this->admin)->get('/admin/wholesale-orders?stage=to_validate&search=ngono')->assertSee($order->order_number);
        $this->actingAs($this->admin)->get('/admin/wholesale-orders?stage=delivered')->assertDontSee($order->order_number);
        $this->actingAs($this->admin)->get('/admin/wholesale-orders?country=TR')->assertDontSee($order->order_number);
    }

    public function test_detail_shows_every_leg_then_admin_validates_and_tracks_to_douala(): void
    {
        $order = $this->order();

        $this->actingAs($this->admin)->get("/admin/wholesale-orders/{$order->id}")
            ->assertOk()
            ->assertSee('Ventilateur de table')
            ->assertSee('Carton de 50')
            ->assertSee('Expédition Chine → Douala (Bateau)')
            ->assertSee('15 000 FCFA', false)   // bateau : 10 kg × 1 500
            ->assertSee('1 789 FCFA', false)    // SOLEX moto
            ->assertSee('66 789 FCFA', false)   // total
            ->assertSee('Maersk')
            ->assertSee('Valider la commande');

        $this->actingAs($this->admin)->post("/admin/wholesale-orders/{$order->id}/confirm")->assertSessionHas('success');
        $order->refresh();
        $this->assertSame('confirmed', $order->status);
        $this->assertNotNull($order->settled_at);

        // Expédition depuis la Chine, puis arrivée à l'entrepôt de Douala.
        $this->actingAs($this->admin)->post("/admin/shipments/{$order->id}/steps", [
            'step' => 'handed_to_carrier', 'carrier_tracking_number' => 'MSK-42',
        ])->assertSessionHas('success');
        $this->actingAs($this->admin)->post("/admin/shipments/{$order->id}/steps", ['step' => 'arrived_hub'])->assertSessionHas('success');

        $this->assertSame('arrived_hub', $order->fresh()->tracking_status);
        $this->actingAs($this->admin)->get("/admin/wholesale-orders/{$order->id}")
            ->assertSee('À Douala · livraison SOLEX')
            ->assertSee('MSK-42')
            ->assertSee(ImportHub::NAME);
    }

    public function test_admin_rejects_and_the_buyer_gets_the_money_back(): void
    {
        $order = $this->order();
        $locked = (float) WalletBalance::where('user_id', $this->buyer->id)->value('locked_balance');
        $this->assertEquals((float) $order->total, $locked);

        $this->actingAs($this->admin)->post("/admin/wholesale-orders/{$order->id}/reject", ['reason' => 'Rupture chez le fournisseur'])
            ->assertSessionHas('success');

        $order->refresh();
        $this->assertSame('cancelled', $order->status);
        $this->assertSame('Rupture chez le fournisseur', $order->cancel_reason);
        $this->assertEquals(0, (float) WalletBalance::where('user_id', $this->buyer->id)->value('locked_balance'));
    }

    public function test_regular_orders_are_not_listed_here(): void
    {
        $order = Order::create(['user_id' => $this->buyer->id, 'status' => 'pending', 'subtotal' => 1000, 'delivery_fee' => 0, 'total' => 1000]);

        $this->actingAs($this->admin)->get("/admin/wholesale-orders/{$order->id}")->assertNotFound();
    }
}
