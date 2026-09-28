<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\ImportShippingOption;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductPriceTier;
use App\Models\Shop;
use App\Models\User;
use App\Models\WalletBalance;
use App\Services\FcmService;
use App\Services\FirebaseMessagingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Commande en gros sur plusieurs couleurs : une ligne par variante, et le
 * palier (prix selon la quantité) lu sur le total de ces lignes.
 */
class WholesaleMultiVariantOrderTest extends TestCase
{
    use RefreshDatabase;

    private User $buyer;
    private Product $product;
    private ProductPriceTier $tier;
    private ImportShippingOption $shipping;
    private \App\Models\DeliveryCityGrid $grid;
    private int $red;
    private int $black;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(FirebaseMessagingService::class, fn ($mock) => $mock->shouldReceive('sendToUser')->andReturn([]));
        $this->mock(FcmService::class, fn ($mock) => $mock->shouldIgnoreMissing());

        // Réception à Douala (Akwa-Nord, Zone 2), puis SOLEX jusqu'au client.
        User::factory()->create(['email' => 'admin@asso.com']);
        (new \Database\Seeders\DeliveryPartnersSeeder())->run();
        $this->grid = \App\Models\DeliveryCityGrid::where('city', 'Douala')->firstOrFail();
        \App\Support\ImportHub::ensureShop()->update(['address' => 'Rue 1.234, Akwa-Nord, Douala']);

        $seller = User::factory()->create();
        $shop = Shop::create(['user_id' => $seller->id, 'name' => 'Import', 'slug' => 'imp-' . uniqid(), 'status' => 'active']);
        $category = Category::create(['name' => 'Mobilier', 'slug' => 'mobilier-' . uniqid()]);

        $this->product = Product::create([
            'user_id' => $seller->id, 'shop_id' => $shop->id, 'category_id' => $category->id,
            'name' => 'Chaise pliante', 'slug' => 'chaise-' . uniqid(), 'price' => 1000, 'currency' => 'XAF',
            'stock' => 0, 'status' => 'active', 'is_wholesale' => true, 'origin_country' => 'CN', 'weight' => '0.2',
        ]);
        $this->tier = ProductPriceTier::create([
            'product_id' => $this->product->id, 'label' => 'Carton de 50', 'unit_price' => 1000,
            'currency' => 'XAF', 'min_quantity' => 50, 'is_active' => true,
        ]);
        // En gros, le stock saisi sur une variante est sans objet : 0 ne doit
        // pas empêcher de commander la couleur.
        $this->red = $this->product->variants()->create(['attributes' => ['Couleur' => 'Rouge'], 'stock' => 0, 'is_active' => true])->id;
        $this->black = $this->product->variants()->create(['attributes' => ['Couleur' => 'Noir'], 'stock' => 0, 'is_active' => true])->id;

        $this->shipping = ImportShippingOption::create([
            'country_code' => 'CN', 'mode' => 'sea', 'rate_type' => 'flat',
            'rate_amount' => 20000, 'currency' => 'XAF', 'is_active' => true,
        ]);

        $this->buyer = User::factory()->create();
        WalletBalance::updateOrCreate(
            ['user_id' => $this->buyer->id, 'currency' => 'XAF'],
            ['balance' => 1_000_000, 'locked_balance' => 0]
        );
        Sanctum::actingAs($this->buyer);
    }

    private function order(array $lines)
    {
        return $this->postJson('/api/v1/import/orders', [
            'items' => array_map(fn ($line) => [
                'product_id' => $this->product->id,
                'price_tier_id' => $this->tier->id,
                'variant_id' => $line[0],
                'quantity' => $line[1],
            ], $lines),
            'shipping_option_id' => $this->shipping->id,
            'payment_mode' => 'wallet',
            'delivery_company_id' => $this->grid->deliverer_company_id,
            'delivery_grid_id' => $this->grid->id,
            'delivery_vehicle' => 'moto',
            'delivery_quarter' => 'Makèpè',
            'delivery_city' => 'Douala',
            'delivery_address' => 'Makèpè, Douala',
            'customer_phone' => '237670000001',
        ]);
    }

    public function test_minimum_is_reached_by_the_total_of_several_colours(): void
    {
        $this->order([[$this->red, 30], [$this->black, 20]])->assertCreated();

        $order = Order::with('items')->latest('id')->firstOrFail();
        $this->assertCount(2, $order->items);
        $this->assertEquals(['Couleur' => 'Rouge'], $order->items->firstWhere('product_variant_id', $this->red)->variant_attributes);
        $this->assertSame(30, (int) $order->items->firstWhere('product_variant_id', $this->red)->quantity);
        $this->assertSame(20, (int) $order->items->firstWhere('product_variant_id', $this->black)->quantity);
        // Produits + bateau jusqu'à Douala + SOLEX en moto (10 kg, Zone 2 → Zone 3, TTC).
        $this->assertEquals(50 * 1000 + 20000 + 1789, (float) $order->total);
    }

    public function test_price_follows_the_tier_reached_by_the_quantity(): void
    {
        $bulk = ProductPriceTier::create([
            'product_id' => $this->product->id, 'label' => 'Carton de 100', 'unit_price' => 750,
            'currency' => 'XAF', 'min_quantity' => 100, 'is_active' => true,
        ]);

        // L'app envoie encore le premier palier : 60 + 40 = 100 atteint le second.
        $this->order([[$this->red, 60], [$this->black, 40]])->assertCreated();

        $order = Order::with('items')->latest('id')->firstOrFail();
        $this->assertTrue($order->items->every(fn ($item) => (int) $item->price_tier_id === $bulk->id));
        $this->assertEquals(750, (float) $order->items->first()->unit_price);
        $this->assertEquals(100 * 750, (float) $order->items->sum('total_price'));
    }

    public function test_between_two_tiers_the_lower_tier_price_applies(): void
    {
        ProductPriceTier::create([
            'product_id' => $this->product->id, 'label' => 'Carton de 100', 'unit_price' => 750,
            'currency' => 'XAF', 'min_quantity' => 100, 'is_active' => true,
        ]);

        $this->order([[$this->red, 70]])->assertCreated();

        $item = Order::with('items')->latest('id')->firstOrFail()->items->first();
        $this->assertSame($this->tier->id, (int) $item->price_tier_id);
        $this->assertEquals(1000, (float) $item->unit_price);
    }

    public function test_without_mixing_each_option_reaches_its_own_tier(): void
    {
        // Poids réduit : 160 unités doivent tenir sur la moto SOLEX.
        $this->product->update(['tier_mix_variants' => false, 'weight' => '0.1']);
        $bulk = ProductPriceTier::create([
            'product_id' => $this->product->id, 'label' => 'Carton de 100', 'unit_price' => 750,
            'currency' => 'XAF', 'min_quantity' => 100, 'is_active' => true,
        ]);

        $this->order([[$this->red, 30], [$this->black, 100]])->assertCreated();
        $order = Order::with('items')->latest('id')->firstOrFail();
        $this->assertEquals(1000, (float) $order->items->firstWhere('product_variant_id', $this->red)->unit_price); // 30 < 50 : prix du 1er palier
        $this->assertEquals(750, (float) $order->items->firstWhere('product_variant_id', $this->black)->unit_price);
        $this->assertSame($bulk->id, (int) $order->items->firstWhere('product_variant_id', $this->black)->price_tier_id);
    }

    public function test_below_the_first_tier_the_first_tier_price_applies(): void
    {
        // Pas de minimum de commande : 20 + 20 sous le seuil de 50.
        $this->order([[$this->red, 20], [$this->black, 20]])->assertCreated();

        $order = Order::with('items')->latest('id')->firstOrFail();
        $this->assertTrue($order->items->every(fn ($item) => (int) $item->price_tier_id === $this->tier->id));
        $this->assertEquals(40 * 1000, (float) $order->items->sum('total_price'));
    }
}
