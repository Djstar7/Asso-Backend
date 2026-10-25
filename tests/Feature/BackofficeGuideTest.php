<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackofficeGuideTest extends TestCase
{
    use RefreshDatabase;

    private function productManager(array $extra = []): User
    {
        return User::factory()->create([
            'role' => 'product_manager',
            'roles' => ['product_manager'],
            'admin_permissions' => $extra,
        ]);
    }

    public function test_admin_sees_every_section_of_the_guide(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->get('/admin/guide')
            ->assertOk()
            ->assertViewHas('allowedPages', fn (array $pages) => in_array('revenus', $pages, true)
                && in_array('gestionnaires', $pages, true)
                && in_array('config-paiements', $pages, true));

        $this->actingAs($admin)->get('/admin/guide/img/revenue.webp')->assertOk();
    }

    public function test_manager_only_sees_the_sections_opened_to_him(): void
    {
        $manager = $this->productManager(['shops']);

        $this->actingAs($manager)->get('/admin/guide')
            ->assertOk()
            ->assertViewHas('allowedPages', function (array $pages) {
                return in_array('produits', $pages, true)
                    && in_array('boutiques', $pages, true)
                    && in_array('bienvenue', $pages, true)
                    && ! in_array('revenus', $pages, true)
                    && ! in_array('gestionnaires', $pages, true)
                    && ! in_array('config-paiements', $pages, true);
            });
    }

    public function test_manager_cannot_load_screenshots_of_closed_sections(): void
    {
        $manager = $this->productManager();

        $this->actingAs($manager)->get('/admin/guide/img/products.webp')->assertOk();
        $this->actingAs($manager)->get('/admin/guide/img/dashboard.webp')->assertOk();
        $this->actingAs($manager)->get('/admin/guide/img/revenue.webp')->assertNotFound();
        $this->actingAs($manager)->get('/admin/guide/img/settings_payments.webp')->assertNotFound();
    }

    public function test_menu_shows_the_guide_link_to_managers(): void
    {
        $manager = $this->productManager();

        $this->actingAs($manager)->get('/admin/products')
            ->assertOk()
            ->assertSee(route('admin.guide'), false)
            ->assertSee('Guide d’utilisation', false);
    }

    public function test_guide_requires_a_backoffice_account(): void
    {
        $this->get('/admin/guide')->assertRedirect();

        $client = User::factory()->create(['role' => 'client']);
        $this->actingAs($client)->get('/admin/guide')->assertRedirect(route('admin.login'));
    }
}
