<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\DelivererCompany;
use App\Models\Order;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Models\WalletBalance;
use App\Services\CommissionService;
use App\Services\FcmService;
use App\Services\FirebaseMessagingService;
use App\Support\DeliveryDelay;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Délai de livraison annoncé, en jours ouvrables (1 à 20) : produit → catégorie →
 * défaut du Dashboard, affiché sur la fiche produit et figé sur la commande.
 */
class ProductDeliveryDelayTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $vendor;
    private Shop $shop;
    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();
        CommissionService::flush();
        $this->mock(FirebaseMessagingService::class, fn ($mock) => $mock->shouldReceive('sendToUser')->andReturn([]));
        $this->mock(FcmService::class, fn ($mock) => $mock->shouldIgnoreMissing());

        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->vendor = User::factory()->create(['role' => 'vendeur']);
        $this->shop = Shop::create([
            'user_id' => $this->vendor->id, 'name' => 'Boutique', 'slug' => 'boutique', 'status' => 'active',
        ]);
        $this->category = Category::create(['name' => 'Meubles', 'slug' => 'meubles']);
    }

    private function product(array $attributes = []): Product
    {
        return Product::create($attributes + [
            'user_id' => $this->vendor->id,
            'shop_id' => $this->shop->id,
            'category_id' => $this->category->id,
            'name' => 'Armoire',
            'slug' => 'armoire-'.uniqid(),
            'price' => 10000,
            'currency' => 'XAF',
            'stock' => 10,
            'status' => 'active',
            'weight' => '2',
        ]);
    }

    public function test_business_days_skip_weekends(): void
    {
        $friday = Carbon::parse('2026-10-09');

        $this->assertSame('2026-10-12', DeliveryDelay::addBusinessDays($friday, 1)->toDateString());
        $this->assertSame('2026-10-16', DeliveryDelay::addBusinessDays($friday, 5)->toDateString());
        $this->assertSame('2026-11-06', DeliveryDelay::addBusinessDays($friday, 20)->toDateString());
    }

    public function test_delay_falls_back_from_product_to_category_to_default(): void
    {
        $product = $this->product();
        $this->assertSame(['min' => 1, 'max' => 20, 'source' => 'default'], DeliveryDelay::forProduct($product));

        DeliveryDelay::setDefaults(3, 8);
        $this->assertSame(['min' => 3, 'max' => 8, 'source' => 'default'], DeliveryDelay::forProduct($product->fresh()));

        $this->category->update(['delivery_days_min' => 5, 'delivery_days_max' => 15]);
        $this->assertSame(['min' => 5, 'max' => 15, 'source' => 'category'], DeliveryDelay::forProduct($product->fresh()));

        $product->update(['delivery_days_min' => 2, 'delivery_days_max' => 4]);
        $this->assertSame(['min' => 2, 'max' => 4, 'source' => 'product'], DeliveryDelay::forProduct($product->fresh()));
    }

    public function test_vendor_sets_delay_and_api_exposes_it(): void
    {
        $product = $this->product();
        Sanctum::actingAs($this->vendor);

        $this->postJson("/api/v1/vendor/products/{$product->id}", [
            '_method' => 'PUT', 'delivery_days_min' => 21,
        ])->assertUnprocessable()->assertJsonValidationErrors('delivery_days_min');

        $this->postJson("/api/v1/vendor/products/{$product->id}", [
            '_method' => 'PUT', 'delivery_days_min' => 6, 'delivery_days_max' => 3,
        ])->assertUnprocessable()->assertJsonValidationErrors('delivery_days_max');

        $this->postJson("/api/v1/vendor/products/{$product->id}", [
            '_method' => 'PUT', 'delivery_days_min' => 3, 'delivery_days_max' => 7,
        ])->assertOk()
            ->assertJsonPath('product.delivery_days_min', 3)
            ->assertJsonPath('product.delivery_delay.max', 7);

        $this->getJson("/api/v1/products/{$product->id}")
            ->assertOk()
            ->assertJsonPath('product.delivery_delay', ['min' => 3, 'max' => 7, 'source' => 'product']);
    }

    public function test_admin_sets_delay_on_product_category_and_default(): void
    {
        $product = $this->product();
        $this->actingAs($this->admin);

        $this->put("/admin/products/{$product->id}", [
            'shop_id' => $this->shop->id,
            'category_id' => $this->category->id,
            'name' => 'Armoire',
            'price_type' => 'fixed',
            'price' => 10000,
            'type' => 'article',
            'weight' => 2,
            'stock' => 10,
            'status' => 'active',
            'delivery_days_min' => 10,
            'delivery_days_max' => 20,
        ])->assertSessionHasNoErrors();
        $this->assertSame([10, 20], [$product->fresh()->delivery_days_min, $product->fresh()->delivery_days_max]);

        $this->put("/admin/settings/categories/{$this->category->id}", [
            'name' => 'Meubles', 'name_en' => 'Furniture', 'delivery_days_min' => 4, 'delivery_days_max' => 12,
        ])->assertSessionHasNoErrors();
        $this->assertSame(12, $this->category->fresh()->delivery_days_max);

        $this->put('/admin/delivery-partners/settings', [
            'delivery_zone_radius_km' => 10,
            'delivery_vat_rate' => 19.25,
            'delivery_default_weight_kg' => 1,
            'delivery_days_min' => 2,
            'delivery_days_max' => 9,
        ])->assertSessionHasNoErrors();
        $this->assertSame(['min' => 2, 'max' => 9], DeliveryDelay::defaults());

        $this->get("/admin/products/{$product->id}/edit")->assertOk()->assertSee('Délai de livraison');
    }

    public function test_order_freezes_longest_delay_and_estimated_dates(): void
    {
        Carbon::setTestNow('2026-10-09 10:00:00'); // vendredi

        $delivererUser = User::factory()->create();
        $company = DelivererCompany::create(['user_id' => $delivererUser->id, 'name' => 'Livreur', 'is_active' => true]);
        $zone = \App\Models\DeliveryZone::create([
            'deliverer_company_id' => $company->id, 'name' => 'Centre', 'city' => 'Douala', 'zone_data' => [], 'is_active' => true,
        ]);
        \App\Models\DeliveryPricelist::create([
            'delivery_zone_id' => $zone->id, 'pricing_type' => 'fixed', 'pricing_data' => ['price' => 400], 'is_active' => true,
        ]);
        $fast = $this->product(['delivery_days_min' => 1, 'delivery_days_max' => 3]);
        $slow = $this->product(['delivery_days_min' => 5, 'delivery_days_max' => 10]);

        $client = User::factory()->create();
        WalletBalance::updateOrCreate(['user_id' => $client->id, 'currency' => 'XAF'], ['balance' => 100000, 'locked_balance' => 0]);

        $orderId = $this->actingAs($client, 'sanctum')->postJson('/api/v1/orders', [
            'items' => [['product_id' => $fast->id, 'quantity' => 1], ['product_id' => $slow->id, 'quantity' => 1]],
            'delivery_company_id' => $company->id,
            'delivery_zone_id' => $zone->id,
            'payment_mode' => 'wallet',
            'wallet_provider' => 'kpay',
            'customer_phone' => '237670000001',
        ])->assertCreated()->json('order_id');

        $snapshot = Order::findOrFail($orderId)->delivery_breakdown;
        $this->assertSame(5, $snapshot['delivery_days_min']);
        $this->assertSame(10, $snapshot['delivery_days_max']);
        $this->assertSame('2026-10-16', $snapshot['estimated_delivery_from']);
        $this->assertSame('2026-10-23', $snapshot['estimated_delivery_to']);

        Carbon::setTestNow();
    }
}
