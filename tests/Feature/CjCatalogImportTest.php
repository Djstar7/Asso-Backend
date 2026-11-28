<?php

namespace Tests\Feature;

use App\Jobs\ImportCjProductJob;
use App\Models\Category;
use App\Models\Product;
use App\Models\Subcategory;
use App\Models\Supplier;
use App\Models\SupplierCategory;
use App\Models\SupplierProduct;
use App\Models\SupplierVariant;
use App\Models\User;
use App\Services\CommissionService;
use App\Services\Suppliers\SupplierImageStore;
use App\Services\Suppliers\SupplierManager;
use App\Support\AssoScope;
use App\Support\ImportHub;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class CjCatalogImportTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://developers.cjdropshipping.com/api2.0/v1/';

    private const PID = '04A22450-67F0-4617-A132-E7AE7F8963B0';

    private Supplier $cj;

    private Category $category;

    private Subcategory $subcategory;

    protected function setUp(): void
    {
        parent::setUp();
        config(['suppliers.cj.base_url' => rtrim(self::BASE, '/'), 'suppliers.cj.requests_per_second' => 0]);
        Sleep::fake();
        Storage::fake('public');
        CommissionService::flush();
        (new \ReflectionProperty(AssoScope::class, 'ownerId'))->setValue(null, null);
        SupplierImageStore::resolveHostsUsing(fn () => ['93.184.216.34']);
        // Taux USD → FCFA figé (cache de l'API live).
        Cache::put('exrate_USD_XAF', 600.0, 3600);

        User::factory()->create(['email' => 'admin@asso.com']);
        ImportHub::ensureShop();
        $this->category = Category::create(['name' => 'Mode', 'name_en' => 'Fashion', 'slug' => 'mode']);
        $this->subcategory = Subcategory::create(['category_id' => $this->category->id, 'name' => 'Sweats', 'name_en' => 'Sweatshirts']);

        $this->cj = Supplier::forCode(Supplier::CJ);
        $this->cj->forceFill([
            'api_key' => 'CJ1@api@key', 'access_token' => 'tok', 'access_token_expires_at' => now()->addDays(100),
            'connected_at' => now(),
        ])->save();
    }

    protected function tearDown(): void
    {
        SupplierImageStore::resolveHostsUsing(null);
        parent::tearDown();
    }

    private function ok(mixed $data): array
    {
        return ['code' => 200, 'result' => true, 'message' => 'Success', 'data' => $data, 'requestId' => 'r', 'success' => true];
    }

    private function png(): string
    {
        $img = imagecreatetruecolor(1600, 800);
        imagefill($img, 0, 0, imagecolorallocate($img, 200, 30, 30));
        ob_start();
        imagepng($img);

        return (string) ob_get_clean();
    }

    private function detail(array $overrides = []): array
    {
        return array_merge([
            'pid' => self::PID,
            'productNameEn' => 'Cat Ear Hoody Coat',
            'productSku' => 'CJNSSYWY01847',
            'bigImage' => 'https://cf.cjdropshipping.com/a.png',
            'productImageSet' => ['https://cf.cjdropshipping.com/a.png', 'https://cf.cjdropshipping.com/b.png', 'http://127.0.0.1/evil.png'],
            'productWeight' => 400,
            'categoryId' => 'CAT-3',
            'productKeyEn' => 'Color-Size',
            'materialNameEnSet' => ['Cotton'],
            'packingNameEnSet' => ['Bag'],
            'description' => '<p>Warm <b>hoody</b>&nbsp;with ears.</p><p>Machine washable.</p>',
            'status' => '3',
            'variants' => [
                ['vid' => 'V1', 'variantSku' => 'CJ-BLK-M', 'variantKey' => 'Black-M', 'variantSellPrice' => 10.0, 'variantWeight' => 400,
                    'variantLength' => 300, 'variantWidth' => 200, 'variantHeight' => 50,
                    'inventories' => [['countryCode' => 'CN', 'totalInventory' => 120], ['countryCode' => 'US', 'totalInventory' => 5]]],
                ['vid' => 'V2', 'variantSku' => 'CJ-BLK-XL', 'variantKey' => 'Black-XL', 'variantSellPrice' => 12.0, 'variantWeight' => 450,
                    'inventories' => [['countryCode' => 'CN', 'totalInventory' => 30]]],
            ],
        ], $overrides);
    }

    private function fakeCj(array $detail, array $freight = [['logisticName' => 'CJPacket Ordinary', 'logisticPrice' => 5, 'logisticAging' => '12-20'], ['logisticName' => 'DHL', 'logisticPrice' => 30, 'logisticAging' => '5-8']]): void
    {
        Http::fake([
            self::BASE.'product/query*' => Http::response($this->ok($detail)),
            self::BASE.'logistic/freightCalculate' => Http::response($this->ok($freight)),
            'https://cf.cjdropshipping.com/*' => Http::response($this->png(), 200, ['Content-Type' => 'image/png']),
        ]);
    }

    private function import(?int $categoryId = null, ?int $subcategoryId = null): SupplierProduct
    {
        return app(SupplierManager::class)->driver(Supplier::CJ)->importer()->import(self::PID, $categoryId, $subcategoryId);
    }

    public function test_categories_are_synced_and_mappings_are_kept(): void
    {
        $tree = [[
            'categoryFirstName' => "Women's Clothing",
            'categoryFirstList' => [[
                'categorySecondName' => 'Tops & Sets',
                'categorySecondList' => [['categoryId' => 'CAT-3', 'categoryName' => 'Hoodies'], ['categoryId' => 'CAT-4', 'categoryName' => 'Shirts']],
            ]],
        ]];
        Http::fake([self::BASE.'product/getCategory' => Http::response($this->ok($tree))]);
        $catalog = app(SupplierManager::class)->driver(Supplier::CJ)->catalog();

        $this->assertSame(2, $catalog->syncCategories());
        $hoodies = SupplierCategory::where('external_id', 'CAT-3')->firstOrFail();
        $this->assertSame("Women's Clothing > Tops & Sets > Hoodies", $hoodies->path);

        $hoodies->update(['category_id' => $this->category->id]);
        $catalog->syncCategories();
        $this->assertSame($this->category->id, $hoodies->refresh()->category_id);
    }

    public function test_a_product_is_imported_with_variants_prices_images_and_delay(): void
    {
        SupplierCategory::create(['supplier_id' => $this->cj->id, 'external_id' => 'CAT-3', 'name' => 'Hoodies', 'path' => 'x > Hoodies',
            'category_id' => $this->category->id, 'subcategory_id' => $this->subcategory->id]);
        $this->fakeCj($this->detail());

        $row = $this->import();

        $this->assertSame(SupplierProduct::IMPORTED, $row->status, (string) $row->last_error);
        $product = Product::withoutGlobalScopes()->with('images', 'variants')->findOrFail($row->product_id);
        $hub = ImportHub::shop();

        // Boutique ASSO Import Douala, détail, produit importé, inactif à la création.
        $this->assertSame($hub->id, $product->shop_id);
        $this->assertSame('inactive', $product->status);
        $this->assertFalse((bool) $product->is_wholesale);
        $this->assertNull($product->origin_country);
        $this->assertSame($this->subcategory->id, $product->subcategory_id);
        $this->assertSame('cj:'.self::PID, $product->client_reference);

        // (10 + 5) USD × 600 × 1,03 × 1,25 = 11 587,5 → 11 600 FCFA ; (12 + 5) → 13 132,5 → 13 150.
        $this->assertEquals(11600, (float) $product->price);
        $this->assertSame('CJPacket Ordinary', $row->logistic_name);
        $this->assertEquals(5, (float) $row->freight_usd);

        // Délai CJ 12-20 jours + 3 jours à Douala.
        $this->assertSame(15, $product->delivery_days_min);
        $this->assertSame(23, $product->delivery_days_max);

        $this->assertCount(2, $product->variants);
        $xl = $product->variants->firstWhere('sku', 'CJ-BLK-XL');
        $this->assertSame(['Couleur' => 'Black', 'Taille' => 'XL'], $xl->attributes);
        $this->assertEquals(1550, (float) $xl->price_adjustment);
        $this->assertSame(30, $xl->stock);
        $this->assertSame(150, (int) $product->stock);
        $this->assertEquals(0.45, (float) $product->weight);

        $this->assertSame($xl->id, SupplierVariant::where('external_id', 'V2')->value('product_variant_id'));

        // Deux photos CJ (l'adresse locale est refusée), réencodées en JPEG ≤ 1200 px.
        $this->assertCount(2, $product->images);
        $path = substr($product->images->first()->image_path, strlen('storage/'));
        Storage::disk('public')->assertExists($path);
        [$w, , $type] = getimagesizefromstring(Storage::disk('public')->get($path));
        $this->assertSame(1200, $w);
        $this->assertSame(IMAGETYPE_JPEG, $type);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '127.0.0.1'));
        // Sans cette option, CJ ne renvoie aucun stock (recette du 28/11).
        Http::assertSent(fn ($r) => str_contains($r->url(), 'product/query') && str_contains($r->url(), 'features=enable_inventory'));

        // Texte nettoyé, traduction anglaise enregistrée.
        $this->assertStringContainsString("Warm hoody with ears.\nMachine washable.", $product->description);
        $this->assertStringContainsString('Matière : Cotton', $product->characteristics);
        $this->assertStringContainsString('Material: Cotton', (string) $product->getTranslation('characteristics', 'en'));
    }

    public function test_reimport_updates_prices_but_keeps_the_admin_status_and_photos(): void
    {
        $this->fakeCj($this->detail());
        $row = $this->import($this->category->id);
        $product = Product::withoutGlobalScopes()->findOrFail($row->product_id);
        $product->update(['status' => 'active']);

        $variants = $this->detail()['variants'];
        $variants[0]['variantSellPrice'] = 20.0;
        unset($variants[1]);
        Http::swap(new \Illuminate\Http\Client\Factory);
        $this->fakeCj($this->detail(['variants' => array_values($variants)]));

        $row = $this->import();

        $product->refresh();
        $this->assertSame('active', $product->status);
        $this->assertCount(2, $product->images()->get());
        // (20 + 5) × 600 × 1,03 × 1,25 = 19 312,5 → 19 350 ; une seule déclinaison avec options reste une déclinaison.
        $this->assertEquals(19350, (float) $product->price);
        $this->assertFalse((bool) SupplierVariant::where('external_id', 'V2')->value('is_active'));
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'cf.cjdropshipping.com'));
        $this->assertSame(SupplierProduct::IMPORTED, $row->status);
    }

    public function test_no_line_to_cameroon_is_recorded_without_creating_a_product(): void
    {
        $this->fakeCj($this->detail(), []);

        $row = $this->import($this->category->id);

        $this->assertSame(SupplierProduct::NO_SHIPPING, $row->status);
        $this->assertNull($row->product_id);
        $this->assertSame(0, Product::withoutGlobalScopes()->count());
    }

    public function test_unmapped_category_without_fallback_is_flagged(): void
    {
        $this->fakeCj($this->detail());

        $row = $this->import();

        $this->assertSame(SupplierProduct::UNMAPPED, $row->status);
        $this->assertNull($row->product_id);
    }

    public function test_a_delisted_product_is_withdrawn_from_sale(): void
    {
        $this->fakeCj($this->detail());
        $row = $this->import($this->category->id);
        Product::withoutGlobalScopes()->whereKey($row->product_id)->update(['status' => 'active']);

        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake([self::BASE.'product/query*' => Http::response(['code' => 1602003, 'result' => false, 'message' => 'Product removed', 'data' => null, 'requestId' => 'x'])]);

        $row = $this->import();

        $this->assertSame(SupplierProduct::DELISTED, $row->status);
        $this->assertSame('inactive', Product::withoutGlobalScopes()->whereKey($row->product_id)->value('status'));
    }

    public function test_the_import_command_lists_then_queues_products(): void
    {
        Queue::fake();
        Http::fake([self::BASE.'product/listV2*' => Http::response($this->ok([
            'pageNumber' => 1, 'totalPages' => 1, 'totalRecords' => 2,
            'content' => [['productList' => [['id' => 'P1', 'nameEn' => 'A'], ['id' => 'P2', 'nameEn' => 'B']]]],
        ]))]);

        $this->artisan('cj:import', ['--keyword' => 'hoodie', '--dry-run' => true])->expectsOutputToContain('2 produit(s)')->assertSuccessful();
        Queue::assertNothingPushed();

        $this->artisan('cj:import', ['--keyword' => 'hoodie', '--limit' => 1, '--asso-category' => $this->category->id])->assertSuccessful();
        Queue::assertPushed(ImportCjProductJob::class, 1);
        Queue::assertPushed(ImportCjProductJob::class, fn ($job) => $job->pid === 'P1' && $job->categoryId === $this->category->id);
    }

    public function test_admin_searches_imports_and_publishes(): void
    {
        Queue::fake();
        $admin = User::factory()->create(['role' => 'admin']);
        Http::fake([self::BASE.'product/listV2*' => Http::response($this->ok([
            'pageNumber' => 1, 'totalPages' => 1, 'totalRecords' => 1,
            'content' => [['productList' => [['id' => self::PID, 'nameEn' => 'Cat Ear Hoody Coat', 'sellPrice' => '11.85', 'bigImage' => 'https://cf.cjdropshipping.com/a.png']]]],
        ]))]);

        $this->actingAs($admin)->get('/admin/suppliers/cj/catalog?keyword=hoodie')->assertOk()->assertSee('Cat Ear Hoody Coat')->assertSee('11.85 USD');

        $this->actingAs($admin)->post('/admin/suppliers/cj/catalog/import', ['pids' => [self::PID], 'target' => $this->category->id.':'.$this->subcategory->id])
            ->assertRedirect('/admin/suppliers/cj/products');
        Queue::assertPushed(ImportCjProductJob::class, fn ($job) => $job->subcategoryId === $this->subcategory->id);

        $this->actingAs($admin)->post('/admin/suppliers/cj/catalog/import', ['pids' => ['../etc']])->assertSessionHasErrors('pids.0');

        // Import réel puis publication depuis la liste.
        Http::swap(new \Illuminate\Http\Client\Factory);
        $this->fakeCj($this->detail());
        $row = $this->import($this->category->id);
        $this->actingAs($admin)->get('/admin/suppliers/cj/products?published=no')->assertOk()->assertSee('Cat Ear Hoody Coat')->assertSee('11 600 FCFA');
        $this->actingAs($admin)->post('/admin/suppliers/cj/products/publish', ['ids' => [$row->id]])->assertSessionHas('success', fn ($m) => str_contains($m, '1 fiche(s) publiée(s)'));
        $this->assertSame('active', Product::withoutGlobalScopes()->whereKey($row->product_id)->value('status'));

        // La fiche publiée s'ouvre dans l'application, avec ses déclinaisons, au prix FCFA.
        $this->getJson("/api/v1/products/{$row->product_id}")->assertOk()
            ->assertJsonFragment(['name' => 'Cat Ear Hoody Coat']);
    }

    public function test_admin_maps_a_category(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $category = SupplierCategory::create(['supplier_id' => $this->cj->id, 'external_id' => 'CAT-3', 'name' => 'Hoodies', 'path' => 'W > T > Hoodies']);

        $this->actingAs($admin)->get('/admin/suppliers/cj/categories')->assertOk()->assertSee('W &gt; T &gt; Hoodies', false);
        $this->actingAs($admin)->put("/admin/suppliers/cj/categories/{$category->id}", ['target' => $this->category->id.':'.$this->subcategory->id])->assertSessionHas('success');
        $this->assertSame($this->subcategory->id, $category->refresh()->subcategory_id);

        $other = Category::create(['name' => 'Autre', 'name_en' => 'Other', 'slug' => 'autre']);
        $this->actingAs($admin)->put("/admin/suppliers/cj/categories/{$category->id}", ['target' => $other->id.':'.$this->subcategory->id])->assertSessionHasErrors('target');
    }

    public function test_managers_cannot_open_the_catalog(): void
    {
        $manager = User::factory()->create(['role' => 'product_manager', 'roles' => ['product_manager'], 'admin_permissions' => ['products.view']]);

        $this->actingAs($manager)->get('/admin/suppliers/cj/catalog')->assertForbidden();
        $this->actingAs($manager)->get('/admin/suppliers/cj/products')->assertForbidden();
    }
}
