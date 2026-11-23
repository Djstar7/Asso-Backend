<?php

namespace Tests\Feature;

use App\Jobs\ImportCjProductJob;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Subcategory;
use App\Models\Supplier;
use App\Models\SupplierProduct;
use App\Models\SupplierVariant;
use App\Models\SupplierWebhookEvent;
use App\Models\User;
use App\Services\CommissionService;
use App\Services\Suppliers\SupplierImageStore;
use App\Services\Suppliers\SupplierManager;
use App\Support\AssoScope;
use App\Support\ImportHub;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class CjWebhookSyncTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://developers.cjdropshipping.com/api2.0/v1/';

    private const PID = '04A22450-67F0-4617-A132-E7AE7F8963B0';

    private const OPEN_ID = '123';

    private Supplier $cj;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'suppliers.cj.base_url' => rtrim(self::BASE, '/'),
            'suppliers.cj.requests_per_second' => 0,
            'suppliers.cj.webhook_base_url' => 'https://api.asso.example',
        ]);
        Sleep::fake();
        Storage::fake('public');
        CommissionService::flush();
        (new \ReflectionProperty(AssoScope::class, 'ownerId'))->setValue(null, null);
        SupplierImageStore::resolveHostsUsing(fn () => ['93.184.216.34']);
        Cache::put('exrate_USD_XAF', 600.0, 3600);

        User::factory()->create(['email' => 'admin@asso.com']);
        ImportHub::ensureShop();
        $category = Category::create(['name' => 'Mode', 'name_en' => 'Fashion', 'slug' => 'mode']);
        Subcategory::create(['category_id' => $category->id, 'name' => 'Sweats', 'name_en' => 'Sweatshirts']);

        $this->cj = Supplier::forCode(Supplier::CJ);
        $this->cj->forceFill([
            'api_key' => 'CJ1@api@key', 'access_token' => 'tok', 'access_token_expires_at' => now()->addDays(100),
            'connected_at' => now(), 'is_active' => true, 'account_id' => self::OPEN_ID,
        ])->save();
        $this->category = $category;
    }

    private Category $category;

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
        $img = imagecreatetruecolor(400, 400);
        ob_start();
        imagepng($img);

        return (string) ob_get_clean();
    }

    private function detail(): array
    {
        return [
            'pid' => self::PID, 'productNameEn' => 'Cat Ear Hoody Coat', 'productSku' => 'CJNSSYWY01847',
            'bigImage' => 'https://cf.cjdropshipping.com/a.png', 'productImageSet' => ['https://cf.cjdropshipping.com/a.png'],
            'productWeight' => 400, 'categoryId' => 'CAT-3', 'productKeyEn' => 'Color-Size', 'description' => 'Warm.', 'status' => '3',
            'variants' => [
                ['vid' => 'V1', 'variantSku' => 'CJ-BLK-M', 'variantKey' => 'Black-M', 'variantSellPrice' => 10.0, 'variantWeight' => 400,
                    'inventories' => [['countryCode' => 'CN', 'totalInventory' => 120]]],
                ['vid' => 'V2', 'variantSku' => 'CJ-BLK-XL', 'variantKey' => 'Black-XL', 'variantSellPrice' => 12.0, 'variantWeight' => 450,
                    'inventories' => [['countryCode' => 'CN', 'totalInventory' => 30]]],
            ],
        ];
    }

    /** Produit importé et publié, comme après le lot 2. */
    private function importedProduct(?array $nextDetail = null): SupplierProduct
    {
        $query = Http::sequence()->push($this->ok($this->detail()));
        if ($nextDetail !== null) {
            $query->push($this->ok($nextDetail));
        }
        Http::fake([
            self::BASE.'product/query*' => $query,
            self::BASE.'logistic/freightCalculate' => Http::response($this->ok([['logisticName' => 'CJPacket Ordinary', 'logisticPrice' => 5, 'logisticAging' => '12-20']])),
            'https://cf.cjdropshipping.com/*' => Http::response($this->png(), 200, ['Content-Type' => 'image/png']),
        ]);
        $row = app(SupplierManager::class)->driver(Supplier::CJ)->importer()->import(self::PID, $this->category->id);
        $this->assertSame(SupplierProduct::IMPORTED, $row->status);
        Product::withoutGlobalScopes()->whereKey($row->product_id)->update(['status' => 'active']);

        return $row;
    }

    private function sign(string $body, string $openId = self::OPEN_ID): string
    {
        return base64_encode(hash_hmac('sha256', $body, $openId, true));
    }

    private function push(array $message, ?string $sign = null, ?string $token = null)
    {
        $body = json_encode($message);

        return $this->call('POST', '/api/v1/suppliers/cj/webhook/'.($token ?? $this->cj->webhook_token), [], [], [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_SIGN' => $sign ?? $this->sign($body)], $body);
    }

    private function stockMessage(string $id, int $cn): array
    {
        return ['messageId' => $id, 'type' => 'STOCK', 'messageType' => 'INCREASE', 'params' => [
            'V1' => [
                ['vid' => 'V1', 'areaId' => '1', 'areaEn' => 'China Warehouse', 'countryCode' => 'CN', 'storageNum' => $cn],
                ['vid' => 'V1', 'areaId' => '2', 'areaEn' => 'US Warehouse', 'countryCode' => 'US', 'storageNum' => 999],
            ],
        ]];
    }

    public function test_signature_matches_the_cj_documentation_example(): void
    {
        $this->cj->forceFill(['account_id' => '123'])->save();
        $webhooks = app(SupplierManager::class)->driver(Supplier::CJ)->webhooks();
        $body = '{"messageId":"123111","messageType":"INSERT","params":"123","type":"PRODUCT"}';

        $this->assertTrue($webhooks->verifySignature($body, 'AHxoGFMoS/4mZfJ5vFes5//Pz2QibFQhh3GlrTtnWpk='));
        $this->assertFalse($webhooks->verifySignature($body, 'wrong'));
        $this->assertFalse($webhooks->verifySignature($body, null));
    }

    public function test_wrong_token_or_signature_is_rejected_and_nothing_is_stored(): void
    {
        $this->push($this->stockMessage('m1', 5), null, str_repeat('x', 48))->assertNotFound();
        $this->push($this->stockMessage('m1', 5), 'forged')->assertStatus(401);

        $this->assertSame(0, SupplierWebhookEvent::count());
        $this->assertSame(1, (int) Cache::get('suppliers:cj:webhook-rejected'));
    }

    public function test_signed_stock_alert_updates_stocks_without_calling_cj_and_duplicates_are_ignored(): void
    {
        $row = $this->importedProduct();
        Http::fake(fn () => throw new \RuntimeException('aucun appel CJ attendu'));

        $this->push($this->stockMessage('stock-1', 7))->assertOk()->assertJson(['code' => 200, 'result' => 'success']);
        $this->push($this->stockMessage('stock-1', 7))->assertOk();

        $this->assertSame(1, SupplierWebhookEvent::count());
        $event = SupplierWebhookEvent::first();
        $this->assertSame(SupplierWebhookEvent::PROCESSED, $event->status);
        $this->assertTrue($event->signature_verified);

        $v1 = SupplierVariant::where('external_id', 'V1')->first();
        $this->assertSame(7, $v1->stock);
        $this->assertSame(7, ProductVariant::find($v1->product_variant_id)->stock);
        // Total = 7 (V1, entrepôt chinois seulement) + 30 (V2 inchangé).
        $this->assertSame(37, (int) Product::withoutGlobalScopes()->find($row->product_id)->stock);
    }

    public function test_without_open_id_alerts_only_trigger_a_reread_from_cj(): void
    {
        $this->importedProduct();
        $this->cj->forceFill(['account_id' => null])->save();
        Queue::fake([ImportCjProductJob::class]);

        $this->push($this->stockMessage('stock-2', 0), 'whatever')->assertOk();

        $this->assertFalse(SupplierWebhookEvent::first()->signature_verified);
        $this->assertSame(120, SupplierVariant::where('external_id', 'V1')->first()->stock);
        Queue::assertPushed(ImportCjProductJob::class, fn ($job) => $job->pid === self::PID);
    }

    public function test_product_and_variant_alerts_reread_known_products_only(): void
    {
        $this->importedProduct();
        Queue::fake([ImportCjProductJob::class]);
        Http::fake([self::BASE.'product/variant/queryByVid*' => Http::response($this->ok(['vid' => 'V9', 'pid' => self::PID]))]);

        $this->push(['messageId' => 'p1', 'type' => 'PRODUCT', 'messageType' => 'DELETE', 'params' => ['pid' => self::PID, 'fields' => ['productStatus']]])->assertOk();
        $this->push(['messageId' => 'p2', 'type' => 'PRODUCT', 'messageType' => 'UPDATE', 'params' => ['pid' => 'UNKNOWN']])->assertOk();
        $this->push(['messageId' => 'v1', 'type' => 'VARIANT', 'messageType' => 'INSERT', 'params' => ['vid' => 'V9']])->assertOk();
        $this->push(['messageId' => 'o1', 'type' => 'ORDER', 'messageType' => 'UPDATE', 'params' => ['cjOrderId' => '1']])->assertOk();

        $statuses = SupplierWebhookEvent::orderBy('id')->pluck('status', 'message_id')->all();
        $this->assertSame(['p1' => 'processed', 'p2' => 'ignored', 'v1' => 'processed', 'o1' => 'ignored'], $statuses);
        // Produit (p1) et déclinaison (v1) du même produit : une seule relecture en file.
        Queue::assertPushed(ImportCjProductJob::class, 1);
    }

    public function test_reread_after_delete_alert_takes_the_product_offline(): void
    {
        // Relecture après l'alerte : CJ répond que le produit n'est plus en vente.
        $row = $this->importedProduct(array_merge($this->detail(), ['status' => '2']));

        $this->push(['messageId' => 'p3', 'type' => 'PRODUCT', 'messageType' => 'DELETE', 'params' => ['pid' => self::PID]])->assertOk();

        $this->assertSame(SupplierProduct::DELISTED, $row->fresh()->status);
        $this->assertSame('inactive', Product::withoutGlobalScopes()->find($row->product_id)->status);
    }

    public function test_enabling_alerts_registers_all_topics_with_the_secret_url(): void
    {
        Http::fake([self::BASE.'webhook/set' => Http::response($this->ok(true))]);
        $webhooks = app(SupplierManager::class)->driver(Supplier::CJ)->webhooks();

        $webhooks->configure(true);

        $this->assertTrue(app(SupplierManager::class)->driver(Supplier::CJ)->webhooks()->isEnabled());
        Http::assertSent(function (HttpRequest $request) {
            $body = $request->data();

            return str_ends_with($request->url(), 'webhook/set')
                && array_keys($body) === ['product', 'stock', 'order', 'logistics']
                && $body['stock']['type'] === 'ENABLE'
                && $body['product']['callbackUrls'] === ['https://api.asso.example/api/v1/suppliers/cj/webhook/'.$this->cj->webhook_token];
        });
    }

    public function test_admin_cannot_enable_alerts_with_a_local_address(): void
    {
        config(['suppliers.cj.webhook_base_url' => 'http://localhost:8000']);
        Http::fake();
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->get('/admin/suppliers/cj/sync')->assertOk()->assertSee('Alertes CJ')->assertSee('https');
        $this->actingAs($admin)->post('/admin/suppliers/cj/sync/enable')->assertSessionHas('error');
        Http::assertNothingSent();
    }

    public function test_admin_enables_alerts_and_products_get_subscribed(): void
    {
        $this->importedProduct();
        Http::fake([
            self::BASE.'webhook/set' => Http::response($this->ok(true)),
            self::BASE.'webhook/product/subscribe' => Http::response($this->ok(['successProductIds' => [self::PID], 'failProductIds' => [], 'subscribeAll' => false])),
        ]);
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->post('/admin/suppliers/cj/sync/enable')->assertSessionHas('success');

        $this->assertNotNull(SupplierProduct::first()->subscribed_at);
        $this->actingAs($admin)->get('/admin/suppliers/cj/sync')->assertOk()->assertSee('1 / 1000');
    }

    public function test_subscriptions_respect_the_limit_and_delisted_products_are_unsubscribed(): void
    {
        $this->cj->putSettings(['webhook_enabled_at' => now()->toIso8601String(), 'subscription_limit' => 2]);
        $imported = $this->importedProduct();
        $imported->update(['status' => SupplierProduct::DELISTED]);
        $product = Product::withoutGlobalScopes()->findOrFail($imported->product_id);
        foreach (['A', 'B', 'C'] as $pid) {
            SupplierProduct::create(['supplier_id' => $this->cj->id, 'external_id' => $pid, 'name' => $pid, 'status' => SupplierProduct::IMPORTED, 'product_id' => $product->id]);
        }
        SupplierProduct::create(['supplier_id' => $this->cj->id, 'external_id' => 'GONE', 'name' => 'gone', 'status' => SupplierProduct::DELISTED, 'subscribed_at' => now()]);
        Http::fake([
            self::BASE.'webhook/product/unsubscribe' => Http::response($this->ok(true)),
            self::BASE.'webhook/product/subscribe' => Http::response($this->ok(['successProductIds' => ['A'], 'failProductIds' => ['B'], 'subscribeAll' => false])),
        ]);

        $result = app(SupplierManager::class)->driver(Supplier::CJ)->webhooks()->syncSubscriptions();

        $this->assertSame(['subscribed' => 1, 'unsubscribed' => 1, 'failed' => 1, 'error' => null], $result);
        $this->assertNull(SupplierProduct::where('external_id', 'GONE')->first()->subscribed_at);
        $this->assertNotNull(SupplierProduct::where('external_id', 'A')->first()->subscribed_at);
        $this->assertNotNull(SupplierProduct::where('external_id', 'B')->first()->subscribe_failed_at);
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), 'webhook/product/subscribe') && $r->data()['productIds'] === ['A', 'B']);
    }

    public function test_nightly_resync_queues_only_stale_products_and_prunes_old_alerts(): void
    {
        $row = $this->importedProduct();
        SupplierProduct::create(['supplier_id' => $this->cj->id, 'external_id' => 'FRESH', 'name' => 'f', 'status' => SupplierProduct::IMPORTED,
            'product_id' => $row->product_id, 'last_synced_at' => now()]);
        $row->forceFill(['last_synced_at' => now()->subDays(2)])->save();
        SupplierWebhookEvent::create(['supplier_id' => $this->cj->id, 'message_id' => 'old', 'type' => 'STOCK', 'payload' => []])
            ->forceFill(['created_at' => now()->subDays(40)])->save();
        Queue::fake([ImportCjProductJob::class]);

        $this->artisan('cj:resync')->assertSuccessful();

        Queue::assertPushed(ImportCjProductJob::class, 1);
        Queue::assertPushed(ImportCjProductJob::class, fn ($job) => $job->pid === self::PID && $job->categoryId === null);
        $this->assertSame(0, SupplierWebhookEvent::count());
        $this->assertNotNull($this->cj->fresh()->setting('last_resync_at'));
    }
}
