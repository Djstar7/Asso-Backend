<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Package;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Models\VendorPackage;
use App\Models\WalletBalance;
use App\Services\FcmService;
use App\Services\FirebaseMessagingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Chaque produit publié et chaque produit sponsorisé est annoncé à tous les
 * utilisateurs, en un seul envoi sur le topic Firebase `all_users`.
 */
class ProductBroadcastTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, array{topic: string, title: string, body: string, data: array}> */
    private array $topicSends = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->mock(FirebaseMessagingService::class, function ($mock) {
            $mock->shouldReceive('sendToUser')->andReturn([]);
            $mock->shouldReceive('sendToTopic')->andReturnUsing(
                function (string $topic, string $title, string $body, array $data = []) {
                    $this->topicSends[] = compact('topic', 'title', 'body', 'data');

                    return ['success' => true];
                }
            );
        });
        $this->mock(FcmService::class, function ($mock) {
            $mock->shouldIgnoreMissing();
        });
    }

    private function vendorWithShop(): array
    {
        $vendor = User::factory()->create(['role' => 'vendeur']);
        $shop = Shop::create([
            'user_id' => $vendor->id,
            'name' => 'Boutique Test',
            'slug' => 'boutique-test-' . $vendor->id,
            'status' => 'active',
            'is_certified' => false,
        ]);

        return [$vendor, $shop];
    }

    private function category(): Category
    {
        return Category::firstOrCreate(['slug' => 'test'], ['name' => 'Test', 'is_active' => true]);
    }

    public function test_publier_un_produit_l_annonce_a_tous_meme_boutique_non_certifiee(): void
    {
        [$vendor, $shop] = $this->vendorWithShop();

        VendorPackage::create([
            'user_id' => $vendor->id,
            'package_id' => Package::create([
                'type' => 'storage',
                'name' => 'Stockage',
                'price' => 1000,
                'duration_days' => 30,
                'storage_size_mb' => 100,
                'is_active' => true,
            ])->id,
            'storage_total_mb' => 100,
            'storage_used_mb' => 0,
            'storage_remaining_mb' => 100,
            'purchased_at' => now(),
            'expires_at' => now()->addDays(30),
            'status' => 'active',
        ]);

        $this->actingAs($vendor, 'sanctum')->post('/api/v1/products', [
            'name' => 'Sac en cuir',
            'description' => 'Un beau sac',
            'price' => 15000,
            'category_id' => $this->category()->id,
            'type' => 'article',
            'condition' => 'new',
            'weight' => 1,
            'images' => [UploadedFile::fake()->image('sac.jpg')],
        ], ['Accept' => 'application/json'])->assertCreated();

        $this->assertCount(1, $this->topicSends);
        $send = $this->topicSends[0];
        $this->assertSame('all_users', $send['topic']);
        $this->assertSame('new_product', $send['data']['type']);
        $this->assertSame((string) Product::first()->id, $send['data']['product_id']);
        $this->assertStringContainsString('Sac en cuir', $send['body']);
        // FCM refuse toute valeur non textuelle dans `data`.
        $this->assertContainsOnly('string', $send['data']);
    }

    public function test_activer_un_sponsoring_l_annonce_a_tous(): void
    {
        [$vendor, $shop] = $this->vendorWithShop();
        WalletBalance::create([
            'user_id' => $vendor->id,
            'currency' => 'XAF',
            'balance' => 5000,
            'locked_balance' => 0,
        ]);

        $product = Product::create([
            'user_id' => $vendor->id,
            'shop_id' => $shop->id,
            'category_id' => $this->category()->id,
            'name' => 'Montre',
            'price' => 10000,
            'stock' => 5,
            'status' => 'active',
        ]);

        $package = Package::create([
            'type' => 'boost',
            'name' => 'Coup de pouce',
            'price' => 1000,
            'duration_days' => 2,
            'reach_users' => 1000,
            'is_active' => true,
        ]);

        $this->actingAs($vendor, 'sanctum')->postJson('/api/v1/packages/subscribe', [
            'package_id' => $package->id,
            'product_id' => $product->id,
            'payment_mode' => 'wallet',
        ])->assertStatus(201);

        $this->assertCount(1, $this->topicSends);
        $send = $this->topicSends[0];
        $this->assertSame('all_users', $send['topic']);
        $this->assertSame('sponsored_product', $send['data']['type']);
        $this->assertSame((string) $product->id, $send['data']['product_id']);
        $this->assertContainsOnly('string', $send['data']);
    }

    public function test_un_forfait_de_stockage_n_annonce_rien(): void
    {
        [$vendor] = $this->vendorWithShop();
        WalletBalance::create([
            'user_id' => $vendor->id,
            'currency' => 'XAF',
            'balance' => 5000,
            'locked_balance' => 0,
        ]);

        $package = Package::create([
            'type' => 'storage',
            'name' => 'Stockage',
            'price' => 1000,
            'duration_days' => 30,
            'storage_size_mb' => 100,
            'is_active' => true,
        ]);

        $this->actingAs($vendor, 'sanctum')->postJson('/api/v1/packages/subscribe', [
            'package_id' => $package->id,
            'payment_mode' => 'wallet',
        ])->assertStatus(201);

        $this->assertSame([], $this->topicSends);
    }
}
