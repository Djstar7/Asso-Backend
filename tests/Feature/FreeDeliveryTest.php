<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\DelivererCompany;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Shop;
use App\Models\User;
use App\Models\WalletBalance;
use App\Services\CommissionService;
use App\Services\FcmService;
use App\Services\FirebaseMessagingService;
use App\Services\FreeDeliveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Livraison gratuite offerte par le vendeur : l'acheteur ne paie pas la course,
 * le partenaire et ASSO sont réglés comme d'habitude, le vendeur finance le prix
 * affiché de la livraison sur sa part.
 */
class FreeDeliveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        CommissionService::flush();
        $this->mock(FirebaseMessagingService::class, function ($mock) {
            $mock->shouldReceive('sendToUser')->andReturn([]);
        });
        $this->mock(FcmService::class, function ($mock) {
            $mock->shouldIgnoreMissing();
        });
        // Vente +5 % ; livraison 400 HT + 25 % = 500 affichés.
        Setting::set('default_sale_commission_rate', '5', 'string', 'commissions');
        Setting::set('delivery_commission_rate', '25', 'string', 'commissions');
    }

    private function setBalance(User $u, float $balance): void
    {
        WalletBalance::updateOrCreate(
            ['user_id' => $u->id, 'currency' => 'XAF'],
            ['balance' => $balance, 'locked_balance' => 0]
        );
    }

    private function bal(User $u): float
    {
        return $u->fresh()->kpayBalanceFor('XAF');
    }

    private function catalog(float $sellerPrice = 10000, bool $shopFree = true, ?bool $productFree = null): array
    {
        $seller = User::factory()->create();
        $delivererUser = User::factory()->create();
        $company = DelivererCompany::create(['user_id' => $delivererUser->id, 'name' => 'Livreur', 'is_active' => true]);
        $zone = \App\Models\DeliveryZone::create([
            'deliverer_company_id' => $company->id,
            'name' => 'Centre',
            'city' => 'Douala',
            'zone_data' => [],
            'is_active' => true,
        ]);
        \App\Models\DeliveryPricelist::create([
            'delivery_zone_id' => $zone->id,
            'pricing_type' => 'fixed',
            'pricing_data' => ['price' => 400],
            'is_active' => true,
        ]);
        $category = Category::create(['name' => 'Mode', 'slug' => 'mode-' . uniqid()]);
        $shop = Shop::create([
            'user_id' => $seller->id,
            'name' => 'Boutique ' . $seller->id,
            'slug' => 'boutique-' . $seller->id,
            'status' => 'active',
            'free_delivery' => $shopFree,
        ]);
        $product = Product::create([
            'user_id' => $seller->id,
            'shop_id' => $shop->id,
            'category_id' => $category->id,
            'name' => 'Sac',
            'slug' => 'sac-' . uniqid(),
            'price' => $sellerPrice,
            'currency' => 'XAF',
            'stock' => 10,
            'status' => 'active',
            'weight' => '1.5',
            'free_delivery' => $productFree,
        ]);

        return compact('seller', 'delivererUser', 'company', 'zone', 'shop', 'product');
    }

    private function placeOrder(array $c): Order
    {
        $client = User::factory()->create();
        $this->setBalance($client, 50000);

        $orderId = $this->actingAs($client, 'sanctum')->postJson('/api/v1/orders', [
            'items' => [['product_id' => $c['product']->id, 'quantity' => 2]],
            'delivery_company_id' => $c['company']->id,
            'delivery_zone_id' => $c['zone']->id,
            'payment_mode' => 'wallet',
            'wallet_provider' => 'kpay',
            'customer_phone' => '237670000001',
        ])->assertCreated()->json('order_id');

        return Order::with('user')->findOrFail($orderId);
    }

    public function test_free_delivery_is_paid_by_seller_and_settled_to_partner_and_asso(): void
    {
        $c = $this->catalog(10000);
        $asso = User::factory()->create(['email' => 'admin@asso.com']);

        $order = $this->placeOrder($c);

        // Acheteur : 2 × 10 500, sans la course.
        $this->assertTrue($order->free_delivery);
        $this->assertEquals(500, (float) $order->free_delivery_amount);
        $this->assertEquals(0, (float) $order->delivery_fee);
        $this->assertEquals(21000, (float) $order->total);
        // Vendeur : 20 000 − 500 affichés.
        $this->assertEquals(19500, (float) $order->vendor_net_amount);

        $this->actingAs($c['seller'], 'sanctum')
            ->postJson("/api/v1/vendor/orders/{$order->id}/validate")
            ->assertOk()
            ->assertJsonPath('order.vendor_amount', 19500)
            ->assertJsonPath('order.free_delivery_amount', 500);

        $this->assertEquals(19500, $this->bal($c['seller']));
        $this->assertEquals(400, $this->bal($c['delivererUser']));
        $this->assertEquals(1000 + 100, $this->bal($asso));
        $this->assertEquals(50000 - 21000, $this->bal($order->user));
    }

    public function test_product_can_opt_out_of_shop_free_delivery(): void
    {
        $order = $this->placeOrder($this->catalog(10000, shopFree: true, productFree: false));

        $this->assertFalse($order->free_delivery);
        $this->assertEquals(500, (float) $order->delivery_fee);
        $this->assertEquals(21500, (float) $order->total);
        $this->assertEquals(20000, (float) $order->vendor_net_amount);
    }

    public function test_product_can_offer_free_delivery_without_the_shop(): void
    {
        $order = $this->placeOrder($this->catalog(10000, shopFree: false, productFree: true));

        $this->assertTrue($order->free_delivery);
        $this->assertEquals(21000, (float) $order->total);
    }

    public function test_free_delivery_is_cancelled_when_it_costs_more_than_the_seller_receives(): void
    {
        // Part vendeur 2 × 200 = 400 < 500 de livraison : l'acheteur paie la course.
        $order = $this->placeOrder($this->catalog(200));

        $this->assertFalse($order->free_delivery);
        $this->assertEquals(0, (float) $order->free_delivery_amount);
        $this->assertEquals(500, (float) $order->delivery_fee);
        $this->assertEquals(400, (float) $order->vendor_net_amount);
    }

    public function test_product_exposes_effective_free_delivery(): void
    {
        $c = $this->catalog(10000);

        $this->getJson("/api/v1/products/{$c['product']->id}")
            ->assertOk()
            ->assertJsonPath('product.free_delivery', true);

        $this->assertTrue(FreeDeliveryService::applies(true, 500, 20000));
        $this->assertFalse(FreeDeliveryService::applies(true, 500, 400));
        $this->assertEquals(20000, FreeDeliveryService::vendorNetXaf([
            ['product_id' => $c['product']->id, 'quantity' => 2],
        ]));
    }

    public function test_vendor_toggles_free_delivery_on_shop_and_product(): void
    {
        $c = $this->catalog(10000, shopFree: false);
        $c['seller']->update(['roles' => ['vendeur']]);

        $this->actingAs($c['seller'], 'sanctum')
            ->putJson('/api/v1/vendor/shop/free-delivery', ['free_delivery' => true])
            ->assertOk()
            ->assertJsonPath('free_delivery', true);
        $this->assertTrue($c['product']->fresh()->hasFreeDelivery());

        $this->actingAs($c['seller'], 'sanctum')
            ->putJson("/api/v1/vendor/products/{$c['product']->id}/free-delivery", ['free_delivery' => false])
            ->assertOk()
            ->assertJsonPath('product.free_delivery', false)
            ->assertJsonPath('product.free_delivery_setting', false);

        // Retour au choix de la boutique.
        $this->actingAs($c['seller'], 'sanctum')
            ->putJson("/api/v1/vendor/products/{$c['product']->id}/free-delivery", ['free_delivery' => null])
            ->assertOk()
            ->assertJsonPath('product.free_delivery', true)
            ->assertJsonPath('product.free_delivery_setting', null);

        // Produit d'un autre vendeur : introuvable.
        $other = User::factory()->create();
        $this->actingAs($other, 'sanctum')
            ->putJson("/api/v1/vendor/products/{$c['product']->id}/free-delivery", ['free_delivery' => true])
            ->assertNotFound();
    }

    public function test_product_manager_sets_free_delivery_on_a_wholesale_product(): void
    {
        \App\Models\ImportCountry::create(['code' => 'CN', 'name' => 'Chine', 'flag' => '🇨🇳', 'sort_order' => 1]);
        $c = $this->catalog(10000, shopFree: false);
        $pm = User::factory()->create(['role' => 'product_manager', 'roles' => ['product_manager'], 'admin_permissions' => []]);

        $payload = [
            'shop_id' => $c['shop']->id,
            'category_id' => $c['product']->category_id,
            'name' => 'Balle robes',
            'price_type' => 'fixed',
            'price' => 150000,
            'type' => 'article',
            'weight' => 45,
            'origin_country' => 'CN',
            'is_wholesale' => 1,
            'stock' => 10,
            'status' => 'active',
        ];

        $this->actingAs($pm)->post('/admin/products', $payload + ['free_delivery' => '1'])
            ->assertRedirect(route('admin.products.index'));
        $product = Product::where('name', 'Balle robes')->firstOrFail();
        $this->assertTrue($product->is_wholesale);
        $this->assertTrue($product->free_delivery);
        $this->assertTrue($product->hasFreeDelivery());

        // Retour au réglage de la boutique (vide).
        $this->actingAs($pm)->put("/admin/products/{$product->id}", $payload + ['free_delivery' => ''])
            ->assertRedirect(route('admin.products.index'));
        $this->assertNull($product->fresh()->free_delivery);

        $this->actingAs($pm)->put("/admin/products/{$product->id}", $payload + ['free_delivery' => '0'])
            ->assertRedirect(route('admin.products.index'));
        $this->assertFalse($product->fresh()->free_delivery);
    }

    public function test_admin_and_shops_managers_toggle_shop_free_delivery(): void
    {
        $c = $this->catalog(10000, shopFree: false);
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->post(route('admin.shops.free-delivery', $c['shop']))->assertRedirect();
        $this->assertTrue($c['shop']->fresh()->free_delivery);
        $this->actingAs($admin)->get(route('admin.shops.show', $c['shop']))
            ->assertOk()
            ->assertSee('Livraison gratuite sur toute la boutique');

        // Gestionnaire produits seul : la boutique ne lui est pas ouverte.
        $pm = User::factory()->create(['role' => 'product_manager', 'roles' => ['product_manager'], 'admin_permissions' => []]);
        $this->actingAs($pm)->post(route('admin.shops.free-delivery', $c['shop']))->assertForbidden();
        $this->assertTrue($c['shop']->fresh()->free_delivery);

        // Avec la section Boutiques, il peut la régler.
        $pm->update(['admin_permissions' => ['shops']]);
        $this->actingAs($pm->fresh())->post(route('admin.shops.free-delivery', $c['shop']))->assertRedirect();
        $this->assertFalse($c['shop']->fresh()->free_delivery);
    }
}
