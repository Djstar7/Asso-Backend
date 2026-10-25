<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AdminConfirmationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_pages_include_one_accessible_localized_confirmation_dialog(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'locale' => 'en']);
        $response = $this->actingAs($admin)->get('/admin/products')->assertOk();
        $html = $response->getContent();
        $this->assertSame(1, substr_count($html, '<dialog id="confirmModal"'));
        $response->assertSee('aria-labelledby="confirmModalTitle"', false)
            ->assertSee('aria-describedby="confirmModalMessage"', false)
            ->assertSee('Confirm action')->assertSee('Cancel')->assertSee('Confirm')
            ->assertSee(asset('js/admin-confirmation.js'), false);
    }

    public function test_owned_browser_sources_no_longer_call_native_confirmation_boxes(): void
    {
        foreach ([resource_path('views'), resource_path('js'), public_path('js')] as $directory) {
            if (! is_dir($directory)) {
                continue;
            }
            foreach (File::allFiles($directory) as $file) {
                if (! in_array($file->getExtension(), ['php', 'js'], true)) {
                    continue;
                }
                $this->assertDoesNotMatchRegularExpression('/\b(?:window\s*\.\s*|globalThis\s*\.\s*)?confirm\s*\(/', $file->getContents(), $file->getPathname());
            }
        }
    }
}
