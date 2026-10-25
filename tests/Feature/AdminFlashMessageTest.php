<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AdminFlashMessageTest extends TestCase
{
    use RefreshDatabase;

    public function test_success_is_rendered_once_on_pages_that_used_to_duplicate_it(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        foreach (['/admin/users', '/admin/products', '/admin/shops', '/admin/packages', '/admin/statistics', '/admin/import-countries', '/admin/wholesale-orders', '/admin/real-estate/listings'] as $url) {
            $response = $this->withSession(['success' => 'unique-success-marker'])->get($url)->assertOk();
            $this->assertSame(1, substr_count($response->getContent(), 'unique-success-marker'), $url);
            $this->assertSame(1, substr_count($response->getContent(), 'id="adminFlash"'), $url);
        }
    }

    public function test_login_uses_the_same_single_success_message(): void
    {
        $response = $this->withSession(['success' => 'login-success-marker'])->get('/admin/login')->assertOk();
        $this->assertSame(1, substr_count($response->getContent(), 'login-success-marker'));
        $response->assertSee('id="adminFlash"', false)->assertSee(asset('js/admin-flash.js'), false);
    }

    public function test_page_templates_do_not_render_their_own_session_success_banner(): void
    {
        foreach (File::allFiles(resource_path('views/admin')) as $file) {
            if ($file->getFilename() === 'flash-message.blade.php') {
                continue;
            }
            $this->assertDoesNotMatchRegularExpression('/session\s*\(\s*[\'"]success[\'"]\s*\)/', $file->getContents(), $file->getPathname());
        }
    }
}
