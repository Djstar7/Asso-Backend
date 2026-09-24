<?php

namespace Tests\Feature;

use App\Jobs\ProcessProductVideo;
use App\Models\Category;
use App\Models\ImportCountry;
use App\Models\Product;
use App\Models\ProductVideo;
use App\Models\Shop;
use App\Models\User;
use App\Services\FcmService;
use App\Services\FirebaseMessagingService;
use App\Services\VideoProcessingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Vidéo de présentation des produits grossistes : envoi par morceaux depuis
 * l'admin, traitement (affiche, aperçu, version fiche), rattachement au
 * produit et diffusion à l'app.
 */
class ProductVideoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Storage::fake('local');

        // La création admin d'un produit actif l'annonce sur le topic Firebase.
        $this->mock(FirebaseMessagingService::class, fn ($mock) => $mock->shouldIgnoreMissing());
        $this->mock(FcmService::class, fn ($mock) => $mock->shouldIgnoreMissing());
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function shopAndCategory(): array
    {
        $vendor = User::factory()->create(['role' => 'vendeur']);
        $shop = Shop::create([
            'user_id' => $vendor->id, 'name' => 'Grossiste', 'slug' => 'grossiste-' . $vendor->id, 'status' => 'active',
        ]);
        $category = Category::create(['name' => 'Textile', 'slug' => 'textile-' . Str::random(4)]);

        return [$shop, $category];
    }

    private function wholesaleProduct(): Product
    {
        ImportCountry::firstOrCreate(['code' => 'CN'], ['name' => 'Chine', 'flag' => '🇨🇳', 'sort_order' => 1, 'is_active' => true]);
        [$shop, $category] = $this->shopAndCategory();

        return Product::create([
            'user_id' => $shop->user_id,
            'shop_id' => $shop->id,
            'category_id' => $category->id,
            'name' => 'Balle robes premium',
            'price' => 150000,
            'stock' => 10,
            'status' => 'active',
            'origin_country' => 'CN',
            'is_wholesale' => true,
            'weight' => 45,
        ]);
    }

    /** Envoie un faux fichier en plusieurs morceaux, comme le formulaire admin. */
    private function sendInChunks(User $admin, string $bytes, string $name, int $chunkSize): \Illuminate\Testing\TestResponse
    {
        $uploadId = (string) Str::uuid();
        $chunks = str_split($bytes, $chunkSize);
        $response = null;

        foreach ($chunks as $i => $chunk) {
            $response = $this->actingAs($admin)->post('/admin/product-videos/chunks', [
                'upload_id' => $uploadId,
                'index' => $i,
                'total' => count($chunks),
                'size' => strlen($bytes),
                'name' => $name,
                'chunk' => UploadedFile::fake()->createWithContent("{$name}.part{$i}", $chunk),
            ], ['Accept' => 'application/json']);
        }

        return $response;
    }

    /** Petite vidéo verticale H.264 + AAC générée par ffmpeg (3 s, 360×640). */
    private function makeSampleVideo(string $absolutePath): void
    {
        $process = new Process([
            'ffmpeg', '-y', '-v', 'error',
            '-f', 'lavfi', '-i', 'testsrc=size=360x640:rate=25',
            '-f', 'lavfi', '-i', 'sine=frequency=440',
            '-t', '3', '-c:v', 'libx264', '-pix_fmt', 'yuv420p', '-c:a', 'aac', '-shortest',
            $absolutePath,
        ]);
        $process->mustRun();
    }

    private function requireFfmpeg(): void
    {
        if (!app(VideoProcessingService::class)->available()) {
            $this->markTestSkipped('ffmpeg absent sur cette machine.');
        }
    }

    // ------------------------------------------------------------------
    // Envoi par morceaux
    // ------------------------------------------------------------------

    public function test_l_envoi_par_morceaux_reassemble_le_fichier_et_lance_le_traitement(): void
    {
        Queue::fake();
        $bytes = random_bytes(2500);

        $response = $this->sendInChunks($this->admin(), $bytes, 'balle-robes.mp4', 1000);

        $response->assertCreated()->assertJsonPath('complete', true);
        $video = ProductVideo::findOrFail($response->json('video.id'));
        $this->assertNull($video->product_id);
        $this->assertSame(ProductVideo::PENDING, $video->status);
        $this->assertSame(2500, $video->size_bytes);
        $this->assertSame($bytes, Storage::disk('public')->get($video->original_path));
        // Les morceaux temporaires ne restent pas sur le disque.
        $this->assertSame([], Storage::disk('local')->directories('video-uploads'));
        Queue::assertPushed(ProcessProductVideo::class, fn ($job) => $job->videoId === $video->id);
    }

    public function test_un_morceau_renvoye_apres_coupure_ne_se_duplique_pas(): void
    {
        Queue::fake();
        $admin = $this->admin();
        $uploadId = (string) Str::uuid();
        $send = fn (int $i, string $content) => $this->actingAs($admin)->post('/admin/product-videos/chunks', [
            'upload_id' => $uploadId, 'index' => $i, 'total' => 2, 'size' => 8, 'name' => 'v.mp4',
            'chunk' => UploadedFile::fake()->createWithContent("v{$i}", $content),
        ], ['Accept' => 'application/json']);

        $send(0, 'AAAA')->assertOk()->assertJsonPath('received', 1);
        $send(0, 'AAAA')->assertOk()->assertJsonPath('received', 1); // renvoi du même morceau
        $response = $send(1, 'BBBB')->assertCreated();

        $video = ProductVideo::findOrFail($response->json('video.id'));
        $this->assertSame('AAAABBBB', Storage::disk('public')->get($video->original_path));
    }

    public function test_un_format_non_video_est_refuse(): void
    {
        $response = $this->sendInChunks($this->admin(), 'pas une vidéo', 'catalogue.pdf', 100);

        $response->assertStatus(422);
        $this->assertSame(0, ProductVideo::count());
    }

    public function test_une_video_trop_lourde_est_refusee_des_le_premier_morceau(): void
    {
        $response = $this->actingAs($this->admin())->post('/admin/product-videos/chunks', [
            'upload_id' => (string) Str::uuid(), 'index' => 0, 'total' => 50,
            'size' => (ProductVideo::MAX_SIZE_MB + 1) * 1024 * 1024, 'name' => 'long.mp4',
            'chunk' => UploadedFile::fake()->createWithContent('long.part0', 'x'),
        ], ['Accept' => 'application/json']);

        $response->assertStatus(422)->assertJsonValidationErrors('size');
    }

    public function test_seul_un_admin_connecte_peut_envoyer(): void
    {
        $this->post('/admin/product-videos/chunks', [], ['Accept' => 'application/json'])->assertUnauthorized();
    }

    // ------------------------------------------------------------------
    // Traitement
    // ------------------------------------------------------------------

    public function test_le_traitement_produit_affiche_apercu_et_version_fiche(): void
    {
        $this->requireFfmpeg();
        $disk = Storage::disk('public');
        $disk->makeDirectory('products/videos/uploads');
        $this->makeSampleVideo($disk->path('products/videos/uploads/sample.mp4'));

        $video = ProductVideo::create([
            'original_path' => 'products/videos/uploads/sample.mp4',
            'size_bytes' => $disk->size('products/videos/uploads/sample.mp4'),
            'status' => ProductVideo::PENDING,
        ]);

        ProcessProductVideo::dispatchSync($video->id);
        $video->refresh();

        $this->assertSame(ProductVideo::READY, $video->status);
        $this->assertSame(360, $video->width);
        $this->assertSame(640, $video->height);
        $this->assertEqualsWithDelta(3.0, $video->duration, 0.2);
        $this->assertTrue($disk->exists($video->poster_path));
        $this->assertTrue($disk->exists($video->preview_path));
        $this->assertTrue($disk->exists($video->path));
        // L'original est remplacé par la version fiche.
        $this->assertNull($video->original_path);
        $this->assertFalse($disk->exists('products/videos/uploads/sample.mp4'));

        // Aperçu des cartes : muet et plus léger que la version fiche.
        $preview = app(VideoProcessingService::class)->probe($disk->path($video->preview_path));
        $this->assertFalse($preview['has_audio']);
        $this->assertLessThanOrEqual(640, max($preview['width'], $preview['height']));
    }

    public function test_un_fichier_sans_video_echoue_avec_un_message(): void
    {
        $this->requireFfmpeg();
        Storage::disk('public')->put('products/videos/uploads/faux.mp4', 'ceci n\'est pas une vidéo');
        $video = ProductVideo::create([
            'original_path' => 'products/videos/uploads/faux.mp4',
            'status' => ProductVideo::PENDING,
        ]);

        ProcessProductVideo::dispatchSync($video->id);

        $video->refresh();
        $this->assertSame(ProductVideo::FAILED, $video->status);
        $this->assertNotEmpty($video->error);
        $this->assertNull($video->toApi());
    }

    // ------------------------------------------------------------------
    // Rattachement au produit (formulaire admin)
    // ------------------------------------------------------------------

    private function adminPayload(Shop $shop, Category $category, array $overrides = []): array
    {
        return array_merge([
            'shop_id' => $shop->id,
            'category_id' => $category->id,
            'name' => 'Balle robes premium',
            'price_type' => 'fixed',
            'price' => 150000,
            'type' => 'article',
            'weight' => 45,
            'origin_country' => 'CN',
            'is_wholesale' => 1,
            'stock' => 10,
            'status' => 'active',
        ], $overrides);
    }

    public function test_la_video_envoyee_est_rattachee_au_produit_grossiste(): void
    {
        ImportCountry::create(['code' => 'CN', 'name' => 'Chine', 'flag' => '🇨🇳', 'sort_order' => 1]);
        [$shop, $category] = $this->shopAndCategory();
        $video = ProductVideo::create(['original_path' => 'x.mp4', 'status' => ProductVideo::READY]);

        $this->actingAs($this->admin())
            ->post('/admin/products', $this->adminPayload($shop, $category, ['video_id' => $video->id]))
            ->assertRedirect(route('admin.products.index'));

        $product = Product::where('name', 'Balle robes premium')->firstOrFail();
        $this->assertSame($product->id, $video->fresh()->product_id);
    }

    public function test_une_video_sur_un_produit_local_est_supprimee(): void
    {
        [$shop, $category] = $this->shopAndCategory();
        Storage::disk('public')->put('products/videos/uploads/local.mp4', 'x');
        $video = ProductVideo::create(['original_path' => 'products/videos/uploads/local.mp4', 'status' => ProductVideo::READY]);

        $this->actingAs($this->admin())
            ->post('/admin/products', $this->adminPayload($shop, $category, [
                'origin_country' => '', 'is_wholesale' => 0, 'video_id' => $video->id,
            ]))
            ->assertRedirect(route('admin.products.index'));

        $this->assertNull(ProductVideo::find($video->id));
        $this->assertFalse(Storage::disk('public')->exists('products/videos/uploads/local.mp4'));
    }

    public function test_remplacer_puis_retirer_la_video_a_la_mise_a_jour(): void
    {
        $product = $this->wholesaleProduct();
        $shop = $product->shop;
        $category = $product->category;
        Storage::disk('public')->put('old.mp4', 'old');
        $old = ProductVideo::create(['product_id' => $product->id, 'original_path' => 'old.mp4', 'status' => ProductVideo::READY]);
        $new = ProductVideo::create(['original_path' => 'new.mp4', 'status' => ProductVideo::READY]);
        $admin = $this->admin();

        $this->actingAs($admin)
            ->put(route('admin.products.update', $product), $this->adminPayload($shop, $category, ['video_id' => $new->id]))
            ->assertRedirect(route('admin.products.index'));

        $this->assertNull(ProductVideo::find($old->id));
        $this->assertFalse(Storage::disk('public')->exists('old.mp4'));
        $this->assertSame($product->id, $new->fresh()->product_id);

        $this->actingAs($admin)
            ->put(route('admin.products.update', $product), $this->adminPayload($shop, $category, ['remove_video' => 1]))
            ->assertRedirect(route('admin.products.index'));

        $this->assertSame(0, ProductVideo::where('product_id', $product->id)->count());
    }

    public function test_les_formulaires_admin_proposent_la_video(): void
    {
        $admin = $this->admin();
        ImportCountry::create(['code' => 'TR', 'name' => 'Turquie', 'flag' => '🇹🇷', 'sort_order' => 2]);

        $this->actingAs($admin)->get('/admin/products/create')
            ->assertOk()
            ->assertSee('Vidéo de présentation')
            ->assertSee(route('admin.product-videos.chunk'), false);

        $product = $this->wholesaleProduct();
        Storage::disk('public')->put('p/poster.jpg', 'jpg');
        Storage::disk('public')->put('p/video.mp4', 'mp4');
        $video = ProductVideo::create([
            'product_id' => $product->id, 'path' => 'p/video.mp4', 'poster_path' => 'p/poster.jpg',
            'duration' => 12, 'original_name' => "robe d'été.mp4", 'status' => ProductVideo::READY,
        ]);

        // La vidéo actuelle est transmise au script, apostrophes échappées.
        $this->actingAs($admin)->get(route('admin.products.edit', $product))
            ->assertOk()
            ->assertSee('Vidéo de présentation')
            // Encodée en JSON dans l'attribut : les « / » y sont échappés.
            ->assertSee(str_replace('/', '\\/', route('admin.product-videos.media', [$video, 'video'])), false)
            ->assertSee('robe d\\u0027\\u00e9t\\u00e9.mp4', false);
    }

    // ------------------------------------------------------------------
    // Diffusion à l'app
    // ------------------------------------------------------------------

    public function test_le_catalogue_expose_la_video_seulement_une_fois_prete(): void
    {
        $product = $this->wholesaleProduct();
        Storage::disk('public')->put('products/videos/1/video.mp4', 'full');
        $video = ProductVideo::create([
            'product_id' => $product->id,
            'path' => 'products/videos/1/video.mp4',
            'width' => 576, 'height' => 1024, 'duration' => 26.6,
            'status' => ProductVideo::PROCESSING,
        ]);

        $this->getJson('/api/v1/import/CN/products')->assertOk()->assertJsonPath('products.0.video', null);

        $video->update(['status' => ProductVideo::READY]);

        $payload = $this->getJson('/api/v1/import/CN/products')->assertOk()->json('products.0.video');
        $this->assertSame($video->id, $payload['id']);
        $this->assertStringEndsWith("/api/v1/import/videos/{$video->id}/video", $payload['url']);
        // Sans aperçu dédié, les cartes lisent la version complète.
        $this->assertStringEndsWith("/api/v1/import/videos/{$video->id}/preview", $payload['preview_url']);
        $this->assertNull($payload['poster_url']);
        $this->assertEqualsWithDelta(0.5625, $payload['aspect_ratio'], 0.0001);

        $this->getJson("/api/v1/import/products/{$product->id}")->assertOk()->assertJsonPath('product.video.id', $video->id);
    }

    public function test_la_video_est_servie_par_plages_pour_ios(): void
    {
        $product = $this->wholesaleProduct();
        Storage::disk('public')->put('products/videos/9/video.mp4', str_repeat('0123456789', 100));
        $video = ProductVideo::create([
            'product_id' => $product->id,
            'path' => 'products/videos/9/video.mp4',
            'status' => ProductVideo::READY,
        ]);

        $full = $this->get("/api/v1/import/videos/{$video->id}/video");
        $full->assertOk();
        $this->assertSame('video/mp4', $full->headers->get('Content-Type'));
        $this->assertSame('bytes', $full->headers->get('Accept-Ranges'));

        $partial = $this->get("/api/v1/import/videos/{$video->id}/video", ['Range' => 'bytes=0-9']);
        $partial->assertStatus(206);
        $this->assertSame('bytes 0-9/1000', $partial->headers->get('Content-Range'));
    }

    public function test_une_video_non_rattachee_ou_non_prete_n_est_pas_servie(): void
    {
        Storage::disk('public')->put('orphan.mp4', 'x');
        $orphan = ProductVideo::create(['original_path' => 'orphan.mp4', 'status' => ProductVideo::READY]);

        $this->get("/api/v1/import/videos/{$orphan->id}/video")->assertNotFound();
        $this->get("/api/v1/import/videos/{$orphan->id}/other")->assertNotFound();
    }

    // ------------------------------------------------------------------
    // Ménage
    // ------------------------------------------------------------------

    public function test_les_videos_abandonnees_sont_purgees_apres_un_jour(): void
    {
        Storage::disk('public')->put('stale.mp4', 'x');
        $stale = ProductVideo::create(['original_path' => 'stale.mp4', 'status' => ProductVideo::READY]);
        $stale->forceFill(['created_at' => now()->subDays(2)])->save();
        $fresh = ProductVideo::create(['original_path' => 'fresh.mp4', 'status' => ProductVideo::READY]);
        $attached = ProductVideo::create([
            'product_id' => $this->wholesaleProduct()->id, 'original_path' => 'kept.mp4', 'status' => ProductVideo::READY,
        ]);
        $attached->forceFill(['created_at' => now()->subDays(5)])->save();

        $this->artisan('product-videos:prune')->assertSuccessful();

        $this->assertNull(ProductVideo::find($stale->id));
        $this->assertFalse(Storage::disk('public')->exists('stale.mp4'));
        $this->assertNotNull(ProductVideo::find($fresh->id));
        $this->assertNotNull(ProductVideo::find($attached->id));
    }
}
