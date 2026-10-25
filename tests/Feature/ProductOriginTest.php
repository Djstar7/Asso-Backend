<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\DelivererCompany;
use App\Models\ImportCountry;
use App\Models\Order;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Models\WalletBalance;
use App\Services\CommissionService;
use App\Services\FcmService;
use App\Services\FirebaseMessagingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Origine du produit : « Produit importé » (ancien « Produit local », origine vide),
 * « Produit local » (fabriqué au Cameroun) ou grossiste (pays d'origine), et menu
 * « Commandes locales » du Dashboard.
 */
class ProductOriginTest extends TestCase
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
        $this->shop = Shop::create(['user_id' => $this->vendor->id, 'name' => 'ASSO Fashion', 'slug' => 'asso-fashion', 'status' => 'active']);
        $this->category = Category::create(['name' => 'Mode', 'slug' => 'mode']);
        ImportCountry::create(['code' => 'CN', 'name' => 'Chine', 'flag' => '🇨🇳', 'sort_order' => 1]);
    }

    private function product(array $attributes = []): Product
    {
        return Product::create($attributes + [
            'user_id' => $this->vendor->id,
            'shop_id' => $this->shop->id,
            'category_id' => $this->category->id,
            'name' => 'Robe',
            'slug' => 'robe-'.uniqid(),
            'price' => 10000,
            'currency' => 'XAF',
            'stock' => 10,
            'status' => 'active',
            'weight' => '1',
        ]);
    }

    private function adminPayload(string $name, ?string $origin): array
    {
        return [
            'shop_id' => $this->shop->id,
            'category_id' => $this->category->id,
            'name' => $name,
            'price_type' => 'fixed',
            'price' => 10000,
            'type' => 'article',
            'weight' => 1,
            'stock' => 5,
            'status' => 'active',
            'origin_country' => $origin,
        ];
    }

    public function test_existing_products_become_imported_without_any_change(): void
    {
        $product = $this->product();

        $this->assertSame(Product::ORIGIN_IMPORTED, $product->origin());
        $this->assertSame('Produit importé', $product->originLabel());
        app()->setLocale('en');
        $this->assertSame('Imported product', $product->originLabel());
        app()->setLocale('fr');

        $this->actingAs($this->admin)->get("/admin/products/{$product->id}/edit")
            ->assertOk()
            ->assertSee('Produit importé')
            ->assertSee('Produit local')->assertSee('value="local"', false)
            ->assertSee('Grossiste Chine');
    }

    public function test_admin_chooses_imported_local_or_wholesale(): void
    {
        $this->actingAs($this->admin);

        $this->post('/admin/products', $this->adminPayload('Importé', 'imported'))->assertSessionHasNoErrors();
        $this->post('/admin/products', $this->adminPayload('Vide', ''))->assertSessionHasNoErrors();
        $this->post('/admin/products', $this->adminPayload('Local', 'local'))->assertSessionHasNoErrors();
        $this->post('/admin/products', $this->adminPayload('Chine', 'CN'))->assertSessionHasNoErrors();
        $this->post('/admin/products', $this->adminPayload('Inconnu', 'ZZ'))->assertSessionHasErrors('origin_country');

        $origin = fn (string $name) => Product::where('name', $name)->firstOrFail()->origin();
        $this->assertSame('imported', $origin('Importé'));
        $this->assertSame('imported', $origin('Vide'));
        $this->assertSame('local', $origin('Local'));
        $this->assertSame('CN', $origin('Chine'));

        // Repasser un produit local en grossiste retire la mention locale.
        $local = Product::where('name', 'Local')->firstOrFail();
        $this->put("/admin/products/{$local->id}", $this->adminPayload('Local', 'CN'))->assertSessionHasNoErrors();
        $this->assertSame(['CN', false], [$local->fresh()->origin_country, $local->fresh()->is_local_made]);

        $this->get('/admin/products?origin_country=local')->assertOk();
        $this->get('/admin/products?origin_country=imported')->assertOk();
    }

    public function test_imported_and_local_products_stay_in_main_catalog(): void
    {
        $this->product(['name' => 'Robe importée']);
        $this->product(['name' => 'Robe locale', 'is_local_made' => true]);
        $this->product(['name' => 'Robe Chine', 'origin_country' => 'CN']);

        $names = collect($this->getJson('/api/v1/products')->assertOk()->json('products'))->pluck('name');
        $this->assertEqualsCanonicalizing(['Robe importée', 'Robe locale'], $names->all());
    }

    public function test_vendor_sets_local_and_api_exposes_it(): void
    {
        $product = $this->product();
        Sanctum::actingAs($this->vendor);

        $this->postJson("/api/v1/vendor/products/{$product->id}", ['_method' => 'PUT', 'is_local_made' => '1'])
            ->assertOk()
            ->assertJsonPath('product.is_local_made', true)
            ->assertJsonPath('product.origin_label', 'Produit local');

        $this->getJson("/api/v1/products/{$product->id}", ['Accept-Language' => 'en'])
            ->assertOk()
            ->assertJsonPath('product.is_local_made', true)
            ->assertJsonPath('product.origin_label', 'Local product');

        $this->postJson("/api/v1/vendor/products/{$product->id}", ['_method' => 'PUT', 'is_local_made' => '0'])
            ->assertOk()
            ->assertJsonPath('product.is_local_made', false);
    }

    public function test_order_with_local_product_appears_in_local_orders_menu(): void
    {
        $delivererUser = User::factory()->create();
        $company = DelivererCompany::create(['user_id' => $delivererUser->id, 'name' => 'Livreur', 'is_active' => true]);
        $zone = \App\Models\DeliveryZone::create([
            'deliverer_company_id' => $company->id, 'name' => 'Centre', 'city' => 'Douala', 'zone_data' => [], 'is_active' => true,
        ]);
        \App\Models\DeliveryPricelist::create([
            'delivery_zone_id' => $zone->id, 'pricing_type' => 'fixed', 'pricing_data' => ['price' => 400], 'is_active' => true,
        ]);
        $client = User::factory()->create();
        WalletBalance::updateOrCreate(['user_id' => $client->id, 'currency' => 'XAF'], ['balance' => 100000, 'locked_balance' => 0]);

        $order = function (Product $product) use ($client, $company, $zone) {
            return Order::findOrFail($this->actingAs($client, 'sanctum')->postJson('/api/v1/orders', [
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
                'delivery_company_id' => $company->id,
                'delivery_zone_id' => $zone->id,
                'payment_mode' => 'wallet',
                'wallet_provider' => 'kpay',
                'customer_phone' => '237670000001',
            ])->assertCreated()->json('order_id'));
        };

        $local = $order($this->product(['name' => 'Savon local', 'is_local_made' => true]));
        $imported = $order($this->product(['name' => 'Savon importé']));
        $this->assertTrue($local->has_local_products);
        $this->assertFalse($imported->has_local_products);

        $this->actingAs($this->admin);
        $this->get(route('admin.local-orders.index'))
            ->assertOk()
            ->assertSee('#'.$local->order_number)
            ->assertDontSee('#'.$imported->order_number);
        $this->get(route('admin.local-orders.index', ['stage' => 'to_confirm']))->assertOk()->assertSee('#'.$local->order_number);
        $this->get(route('admin.local-orders.show', $local))->assertOk()->assertSee('Savon local')->assertSee('Produit local');
        $this->get(route('admin.local-orders.show', $imported))->assertNotFound();
        $this->get(route('admin.dashboard'))->assertSee('Commandes locales');
    }

    public function test_local_orders_section_needs_its_permission(): void
    {
        $manager = User::factory()->create(['role' => 'product_manager', 'roles' => ['product_manager'], 'admin_permissions' => []]);
        $this->actingAs($manager)->get(route('admin.local-orders.index'))->assertForbidden();

        $manager->update(['admin_permissions' => ['local_orders']]);
        $this->actingAs($manager->fresh())->get(route('admin.local-orders.index'))->assertOk();
    }
}
