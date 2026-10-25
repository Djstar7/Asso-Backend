<?php

namespace Tests\Feature;

use App\Models\Banner;
use App\Models\Credential;
use App\Models\Document;
use App\Models\ProductVideo;
use App\Models\Shop;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\SafeUpload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminDashboardHardeningTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    public function test_login_limit_is_shared_by_both_portals_and_expires(): void
    {
        $user = $this->admin();
        for ($i = 0; $i < 5; $i++) {
            $this->post('/admin/login', ['email' => $user->email, 'password' => 'incorrect'])->assertRedirect();
        }
        $this->post('/admin/gestionnaire/login', ['email' => $user->email, 'password' => 'incorrect'])
            ->assertStatus(429)->assertHeader('Retry-After');
        $this->withHeader('X-Forwarded-For', '192.0.2.123')->post('/admin/login', ['email' => $user->email, 'password' => 'incorrect'])
            ->assertStatus(429);
        $this->travel(61)->seconds();
        $this->post('/admin/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect();
        $this->assertAuthenticatedAs($user);
    }

    public function test_admin_errors_hide_exception_details_and_preserve_status(): void
    {
        Route::middleware('web')->get('/admin/_audit-failure', fn () => throw new \RuntimeException('private-audit-secret'));
        $this->get('/admin/_audit-failure')->assertStatus(500)->assertDontSee('private-audit-secret');
        $this->getJson('/admin/_audit-failure')->assertStatus(500)->assertDontSee('private-audit-secret')->assertJsonStructure(['message']);
    }

    public function test_non_staff_ajax_requests_receive_a_clear_forbidden_response(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'client']))->getJson('/admin/dashboard')
            ->assertForbidden()->assertJsonStructure(['message']);
    }

    public function test_flash_messages_are_always_escaped(): void
    {
        $payload = '<img src=x onerror=alert(1)>';
        $this->actingAs($this->admin())->withSession(['success' => $payload])->get('/admin/dashboard')
            ->assertOk()->assertSee($payload)->assertDontSee($payload, false);
    }

    public function test_untrusted_shop_names_cannot_break_an_action_button(): void
    {
        $payload = "');window.auditClick=true;//";
        $owner = User::factory()->create(['role' => 'vendeur']);
        $shop = Shop::create(['name' => $payload, 'slug' => 'audit-shop', 'user_id' => $owner->id, 'status' => 'active']);
        $response = $this->actingAs($this->admin())->get('/admin/dashboard')->assertOk();
        $doc = new \DOMDocument;
        @$doc->loadHTML($response->getContent());
        $handler = '';
        foreach ($doc->getElementsByTagName('button') as $button) {
            if (str_starts_with($button->getAttribute('onclick'), 'showRejectModal('.$shop->id.',')) {
                $handler = $button->getAttribute('onclick');
            }
        }
        $this->assertNotSame('', $handler);
        $this->assertStringNotContainsString($payload, $handler);
        $this->assertStringContainsString('\\u0027', $handler);
        file_put_contents('/tmp/asso-admin-action-security.html', $response->getContent());
    }

    public function test_vault_routes_and_transaction_export_are_available(): void
    {
        $this->actingAs($this->admin());
        $credential = Credential::create(['name' => 'Audit', 'type' => 'password', 'password' => 'audit-not-a-real-secret']);
        $this->get('/admin/vault-categories')->assertOk();
        $this->get('/admin/vault/'.$credential->id)->assertRedirect(route('admin.vault.edit', $credential));
        $this->getJson('/admin/vault/'.$credential->id.'/reveal')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->get('/admin/transactions-export-pdf')->assertOk()->assertSee('Total des crédits');
    }

    public function test_transaction_dates_are_validated_for_page_and_exports(): void
    {
        $this->actingAs($this->admin());
        foreach (['/admin/transactions', '/admin/transactions-export-pdf', '/admin/transactions-export-excel'] as $url) {
            $this->getJson($url.'?start_date=not-a-date')->assertUnprocessable()->assertJsonValidationErrors('start_date');
            $this->getJson($url.'?start_date[]=invalid&end_date=2026-10-25')->assertUnprocessable()->assertJsonValidationErrors('start_date');
            $this->getJson($url.'?start_date=2026-10-25&end_date=2026-01-01')->assertUnprocessable()->assertJsonValidationErrors('end_date');
        }
    }

    public function test_statistics_obey_single_date_and_provider_filters(): void
    {
        $user = $this->admin();
        foreach ([['2026-01-01', 100, 'kpay'], ['2026-02-01', 200, 'kpay'], ['2026-02-01', 400, 'paypal']] as [$date, $amount, $provider]) {
            $transaction = WalletTransaction::create(['user_id' => $user->id, 'type' => 'credit', 'amount' => $amount, 'balance_before' => 0, 'balance_after' => $amount, 'description' => 'Audit', 'status' => 'completed', 'provider' => $provider]);
            $transaction->forceFill(['created_at' => $date])->save();
        }
        $this->actingAs($user)->get('/admin/transactions?start_date=2026-02-01&payment_method=kpay')
            ->assertOk()->assertViewHas('stats', fn ($stats) => (int) $stats['total_credits'] === 200)
            ->assertViewHas('chartData', fn ($chart) => count($chart) <= 30);
        $this->get('/admin/transactions/'.$transaction->id)->assertOk()->assertSee('WAL-'.$transaction->id)->assertSee('Solde après');
    }

    public function test_documents_are_private_on_disk_and_active_content_is_rejected(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $this->actingAs($this->admin());
        $this->postJson('/admin/documents', ['title' => 'Audit', 'visibility' => 'private', 'file' => UploadedFile::fake()->createWithContent('audit.html', '<p>audit</p>')])
            ->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->post('/admin/documents', ['title' => 'Audit', 'visibility' => 'private', 'file' => UploadedFile::fake()->createWithContent('audit.txt', 'audit harmless document')])->assertRedirect();
        $document = Document::firstOrFail();
        $this->assertSame('local', $document->file_disk);
        Storage::disk('local')->assertExists($document->file_path);
        Storage::disk('public')->assertMissing($document->file_path);
        $this->get('/admin/documents/'.$document->id.'/download')->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_private_documents_are_not_listed_or_downloadable_by_other_staff(): void
    {
        $owner = $this->admin();
        $document = Document::create(['title' => 'private-document-marker', 'file_name' => 'a.txt', 'file_path' => 'documents/a.txt', 'file_disk' => 'local', 'file_type' => 'txt', 'file_size' => 1, 'mime_type' => 'text/plain', 'visibility' => 'private', 'uploaded_by' => $owner->id]);
        $manager = User::factory()->create(['role' => 'product_manager', 'roles' => ['product_manager'], 'admin_permissions' => ['documents']]);
        $this->actingAs($manager)->get('/admin/documents')->assertOk()->assertDontSee('private-document-marker');
        $this->get('/admin/documents/'.$document->id.'/download')->assertForbidden();
        $this->get('/admin/documents/'.$document->id.'/edit')->assertForbidden();
        $this->delete('/admin/documents/'.$document->id)->assertForbidden();
    }

    public function test_legacy_document_migration_verifies_and_removes_public_copies(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        Storage::disk('public')->put('documents/legacy.txt', 'legacy-data');
        $owner = $this->admin();
        foreach (range(1, 2) as $i) {
            Document::create(['title' => 'Legacy '.$i, 'file_name' => 'legacy.txt', 'file_path' => 'documents/legacy.txt', 'file_disk' => 'public', 'file_type' => 'txt', 'file_size' => 11, 'mime_type' => 'text/plain', 'visibility' => 'private', 'uploaded_by' => $owner->id]);
        }
        $this->artisan('documents:secure-storage')->assertSuccessful();
        Storage::disk('public')->assertMissing('documents/legacy.txt');
        foreach (Document::all() as $document) {
            $this->assertSame('local', $document->file_disk);
            $this->assertSame('legacy-data', Storage::disk('local')->get($document->file_path));
        }
        $this->artisan('documents:secure-storage')->assertSuccessful();
    }

    public function test_a_non_image_logo_is_rejected_by_the_server(): void
    {
        $this->actingAs($this->admin())->putJson(route('admin.settings.update'), ['app_logo' => UploadedFile::fake()->createWithContent('audit.html', '<p>audit</p>')])
            ->assertUnprocessable()->assertJsonValidationErrors('app_logo');
    }

    public function test_failed_replacement_keeps_the_previous_file_and_database_reference(): void
    {
        $disk = Storage::fake('public');
        $disk->put('banners/old.png', 'old-bytes');
        $banner = Banner::create(['image_path' => 'banners/old.png', 'position' => 'home_top']);
        $broken = \Mockery::mock(\Illuminate\Filesystem\FilesystemAdapter::class);
        $broken->shouldReceive('putFileAs')->once()->andReturn(false);
        Storage::shouldReceive('disk')->with('public')->andReturn($broken);
        try {
            SafeUpload::save($banner, [], ['image_path' => [UploadedFile::fake()->image('new.png'), 'banners', '']]);
            $this->fail('An unsuccessful write must fail.');
        } catch (\RuntimeException $e) {
            $this->assertSame('banners/old.png', $banner->fresh()->image_path);
            $this->assertSame('old-bytes', $disk->get('banners/old.png'));
        }
    }

    public function test_manager_cannot_read_or_delete_another_upload(): void
    {
        $owner = User::factory()->create(['role' => 'vendeur']);
        $video = ProductVideo::create(['uploaded_by' => $owner->id, 'original_path' => 'audit.mp4', 'status' => 'pending']);
        $manager = User::factory()->create(['role' => 'product_manager', 'roles' => ['product_manager']]);
        $this->actingAs($manager)->getJson('/admin/product-videos/'.$video->id)->assertNotFound();
        $this->deleteJson('/admin/product-videos/'.$video->id)->assertNotFound();
        $this->assertModelExists($video);
    }

    public function test_database_failure_during_replacement_removes_only_the_new_file(): void
    {
        $disk = Storage::fake('public');
        $disk->put('banners/old.png', 'old-bytes');
        $banner = Banner::create(['image_path' => 'banners/old.png', 'position' => 0]);
        Banner::saving(fn () => throw new \RuntimeException('Simulated database failure'));
        try {
            SafeUpload::save($banner, [], ['image_path' => [UploadedFile::fake()->image('new.png'), 'banners', '']]);
            $this->fail('A database failure must be reported.');
        } catch (\RuntimeException $e) {
            $this->assertSame('banners/old.png', $banner->fresh()->image_path);
            $this->assertSame(['banners/old.png'], $disk->allFiles('banners'));
        }
    }

    public function test_failed_upload_redirect_returns_an_error_instead_of_navigating_away(): void
    {
        Route::middleware('web')->post('/admin/_audit-rejected', fn () => back()->with('error', 'Vérifiez les informations.'));
        $this->actingAs($this->admin())->withHeaders(['X-Admin-Upload' => '1', 'Accept' => 'application/json'])
            ->post('/admin/_audit-rejected')->assertUnprocessable()->assertJson(['message' => 'Vérifiez les informations.']);
    }

    public function test_chunks_enforce_owner_parameters_and_actual_byte_budget(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        Queue::fake();
        $owner = $this->admin();
        $id = (string) Str::uuid();
        $send = fn ($user, $changes = [], $content = 'ab') => $this->actingAs($user)->postJson('/admin/product-videos/chunks', array_replace([
            'upload_id' => $id, 'index' => 0, 'total' => 2, 'size' => 4, 'name' => 'audit.mp4', 'chunk' => UploadedFile::fake()->createWithContent('part', $content),
        ], $changes));
        $send($owner)->assertOk();
        $send($this->admin())->assertNotFound();
        $send($owner, ['size' => 8])->assertConflict();
        $send($owner, ['index' => 1], 'oversized')->assertUnprocessable();
        $send($owner, ['index' => 1])->assertCreated();
        $send($owner, ['index' => 1])->assertCreated();
        $this->assertSame(1, ProductVideo::count());
        Queue::assertPushed(\App\Jobs\ProcessProductVideo::class, 1);
    }

    public function test_upload_retries_return_the_original_result_without_repeating_the_action(): void
    {
        Route::middleware(['web', 'auth', 'backoffice', 'admin.submission'])->post('/admin/_audit-submit', function (Request $request) {
            Cache::increment('audit-save-count');

            return redirect('/admin/dashboard')->with('success', 'Enregistré');
        });
        $this->actingAs($this->admin())->withHeaders(['X-Admin-Upload' => '1', 'Accept' => 'application/json']);
        $data = ['_submission_id' => (string) Str::uuid()];
        $this->post('/admin/_audit-submit', $data)->assertOk()->assertJsonStructure(['redirect']);
        $this->post('/admin/_audit-submit', $data)->assertOk()->assertJsonStructure(['redirect']);
        $this->assertSame(1, Cache::get('audit-save-count'));
        $user = auth()->user();
        $user->forceFill(['role' => 'client', 'roles' => ['client']])->save();
        $this->actingAs($user->fresh())->post('/admin/_audit-submit', $data)->assertForbidden();
        $this->assertSame(1, Cache::get('audit-save-count'));
    }
}
