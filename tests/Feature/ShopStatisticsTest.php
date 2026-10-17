<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Shop;
use App\Models\ShopAnalyticsEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * P8 — Statistiques boutiques : collecte (visites, consultations, contacts),
 * compteurs vendeur (commandes, ventes, CA au prix vendeur) et administration.
 */
class ShopStatisticsTest extends TestCase
{
    use RefreshDatabase;

    private User $vendor;
    private User $buyer;
    private Shop $shop;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->vendor = User::factory()->create(['role' => 'vendeur']);
        $this->buyer = User::factory()->create(['role' => 'client']);
        $this->shop = Shop::create([
            'user_id' => $this->vendor->id, 'name' => 'Boutique Kira', 'slug' => 'kira',
            'status' => 'active', 'verified_at' => now(),
        ]);
        $category = Category::create(['name' => 'Mode', 'slug' => 'mode']);
        $this->product = Product::create([
            'user_id' => $this->vendor->id, 'shop_id' => $this->shop->id, 'category_id' => $category->id,
            'name' => 'Robe wax', 'price' => 10000, 'stock' => 10, 'status' => 'active',
        ]);
    }

    private function order(string $status, int $qty = 1, array $extra = []): Order
    {
        $order = Order::create(array_merge([
            'user_id' => $this->buyer->id, 'status' => $status,
            'subtotal' => 11000 * $qty, 'total' => 11000 * $qty, 'delivery_fee' => 0,
            'payment_method' => 'wallet_kpay', 'payment_status' => 'paid',
            'vendor_net_amount' => 10000 * $qty,
            // Le vendeur est réglé à la validation (settleOrder).
            'settled_at' => in_array($status, ['pending', 'cancelled'], true) ? null : now(),
        ], $extra));
        $order->items()->create([
            'product_id' => $this->product->id, 'seller_id' => $this->vendor->id, 'quantity' => $qty,
            'unit_price' => 11000, 'total_price' => 11000 * $qty,
            'seller_unit_price' => 10000, 'seller_total_price' => 10000 * $qty,
        ]);

        return $order;
    }

    public function test_guest_product_view_is_recorded_once_per_window(): void
    {
        $payload = ['event' => 'product_view', 'product_id' => $this->product->id];

        $this->postJson('/api/v1/analytics/track', $payload)->assertOk()->assertJsonPath('recorded', true);
        $this->postJson('/api/v1/analytics/track', $payload)->assertOk()->assertJsonPath('recorded', false);

        $this->assertSame(1, ShopAnalyticsEvent::where('event_type', 'product_view')->count());
        $this->assertSame($this->shop->id, ShopAnalyticsEvent::first()->shop_id);
    }

    public function test_dedup_window_zero_counts_every_open(): void
    {
        Setting::set('analytics_dedup_minutes', 0, 'integer', 'analytics');
        $payload = ['event' => 'product_view', 'product_id' => $this->product->id];

        $this->postJson('/api/v1/analytics/track', $payload);
        $this->postJson('/api/v1/analytics/track', $payload);

        $this->assertSame(2, ShopAnalyticsEvent::count());
    }

    public function test_owner_visits_are_ignored_and_public_shop_page_counts_a_visit(): void
    {
        Sanctum::actingAs($this->vendor);
        $this->postJson('/api/v1/analytics/track', ['event' => 'product_view', 'product_id' => $this->product->id])
            ->assertJsonPath('recorded', false);

        $this->getJson("/api/v1/shops/{$this->shop->id}")->assertOk();
        $this->assertSame(0, ShopAnalyticsEvent::count());

        Sanctum::actingAs($this->buyer);
        $this->getJson("/api/v1/shops/{$this->shop->id}")->assertOk();
        $this->assertSame(1, ShopAnalyticsEvent::where('event_type', 'shop_view')->where('user_id', $this->buyer->id)->count());
    }

    public function test_disabled_tracking_records_nothing(): void
    {
        Setting::set('analytics_tracking_enabled', false, 'boolean', 'analytics');

        $this->postJson('/api/v1/analytics/track', ['event' => 'contact', 'shop_id' => $this->shop->id])
            ->assertJsonPath('recorded', false);
        $this->assertSame(0, ShopAnalyticsEvent::count());
    }

    public function test_vendor_statistics_combine_audience_orders_and_seller_revenue(): void
    {
        Sanctum::actingAs($this->buyer);
        $this->getJson("/api/v1/shops/{$this->shop->id}");
        $this->postJson('/api/v1/analytics/track', ['event' => 'product_view', 'product_id' => $this->product->id]);
        $this->postJson('/api/v1/analytics/track', ['event' => 'contact', 'product_id' => $this->product->id]);

        $this->order('delivered', 2);
        $this->order('pending');
        $this->order('cancelled');

        Sanctum::actingAs($this->vendor);
        $res = $this->getJson('/api/v1/vendor/statistics?period=7d')->assertOk();

        $res->assertJsonPath('data.period.key', '7d')
            ->assertJsonPath('data.totals.visits', 1)
            ->assertJsonPath('data.totals.unique_visitors', 1)
            ->assertJsonPath('data.totals.product_views', 1)
            ->assertJsonPath('data.totals.contacts', 1)
            ->assertJsonPath('data.totals.orders', 2)
            ->assertJsonPath('data.totals.pending_orders', 1)
            ->assertJsonPath('data.totals.cancelled_orders', 1)
            ->assertJsonPath('data.totals.validated_orders', 1)
            ->assertJsonPath('data.totals.items_sold', 2)
            ->assertJsonPath('data.top_products.0.name', 'Robe wax')
            ->assertJsonPath('data.top_products.0.views', 1)
            ->assertJsonCount(7, 'data.series');

        // CA vendeur = prix vendeur (hors commission ASSO), volume client à part.
        $this->assertEquals(20000, $res->json('data.totals.revenue'));
        $this->assertEquals(22000, $res->json('data.totals.gross_sales'));
        $this->assertEquals(2000, $res->json('data.totals.commission'));
        $this->assertEquals(100.0, $res->json('data.totals.conversion_rate'));
        $this->assertEquals(20000, collect($res->json('data.series'))->sum('revenue'));

        // Les compteurs du tableau de bord et de la boutique viennent de la même source.
        $this->getJson('/api/v1/vendor/dashboard')
            ->assertJsonPath('data.stats.total_visits', 1)
            ->assertJsonPath('data.stats.total_product_views', 1)
            ->assertJsonPath('data.stats.pending_orders', 1);
        $this->assertEquals(20000, $this->getJson('/api/v1/vendor/dashboard')->json('data.stats.total_sales'));
        $this->getJson('/api/v1/vendor/shop')->assertJsonPath('stats.total_visits', 1);
    }

    public function test_revenue_is_what_the_vendor_actually_receives(): void
    {
        // Livraison offerte par le vendeur : 2 385 déduits de sa part.
        $this->order('delivered', 1, ['free_delivery' => true, 'free_delivery_amount' => 2385, 'vendor_net_amount' => 7615]);
        // Acompte payé, solde non payé : vendeur pas encore réglé.
        $this->order('confirmed', 1, ['payment_plan' => 'deposit', 'settled_at' => null]);
        // Paiement carte abandonné : pas une commande.
        $this->order('pending', 1, ['payment_method' => 'stripe_direct', 'payment_status' => 'pending']);

        Sanctum::actingAs($this->vendor);
        $stats = $this->getJson('/api/v1/vendor/dashboard')->assertOk()->json('data.stats');

        $this->assertEquals(7615, $stats['total_sales']);
        $this->assertSame(2, $stats['total_orders']);
    }

    public function test_long_periods_are_grouped_by_month(): void
    {
        Sanctum::actingAs($this->vendor);
        $this->getJson('/api/v1/vendor/statistics?period=365d')
            ->assertOk()
            ->assertJsonPath('data.period.granularity', 'month');
        $this->getJson('/api/v1/vendor/statistics?period=all')->assertOk();
    }

    public function test_non_vendor_cannot_read_statistics(): void
    {
        Sanctum::actingAs($this->buyer);
        $this->getJson('/api/v1/vendor/statistics')->assertForbidden();
    }

    public function test_admin_can_consult_configure_and_reset_statistics(): void
    {
        ShopAnalyticsEvent::create([
            'shop_id' => $this->shop->id, 'event_type' => 'shop_view', 'visitor_hash' => 'x', 'created_at' => now(),
        ]);
        $this->order('delivered');
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->get('/admin/statistics?period=30d&sort=visits')
            ->assertOk()
            ->assertSee('Boutique Kira')
            ->assertSee('Commission ASSO');

        $this->actingAs($admin)->get("/admin/statistics/shops/{$this->shop->id}?period=90d")
            ->assertOk()
            ->assertSee('Robe wax');

        $this->actingAs($admin)->get("/admin/shops/{$this->shop->id}")
            ->assertOk()
            ->assertSee('Voir les statistiques détaillées');

        $this->actingAs($admin)->post('/admin/statistics/settings', [
            'analytics_tracking_enabled' => '1', 'analytics_dedup_minutes' => 60,
        ])->assertRedirect();
        $this->assertSame(60, Setting::get('analytics_dedup_minutes'));

        $this->actingAs($admin)->post("/admin/statistics/shops/{$this->shop->id}/reset-audience")->assertRedirect();
        $this->assertSame(0, ShopAnalyticsEvent::count());
    }
}
