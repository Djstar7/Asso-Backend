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
        $this->actingAs($manager)->get('/admin/guide/img/navigation.png')->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->actingAs($manager)->get('/admin/guide/img/navigation.php')->assertNotFound();
        $this->assertSame([1440, 900], array_slice(getimagesize(resource_path('guide/img/navigation.png')), 0, 2));
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

    public function test_guide_follows_menu_order_and_documents_every_visible_entry(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $response = $this->actingAs($admin)->get('/admin/guide')->assertOk();
        $groups = $response->viewData('guideNavigation');
        $this->assertSame(array_column(config('admin_navigation'), 'id'), array_column($groups, 'id'));
        $articles = [];
        $existing = file_get_contents(resource_path('views/admin/guide/index.blade.php'));
        $supplementary = file_get_contents(resource_path('views/admin/guide/_menu_articles.blade.php'));
        foreach ($groups as $group) {
            foreach ($group['items'] as $item) {
                $this->assertSame(['Menu', $group['label'], $item['label']], $item['path']);
                $this->assertContains($item['article_id'], $response->viewData('allowedPages'));
                $this->assertNotContains($item['article_id'], $articles);
                $articles[] = $item['article_id'];
                if (str_starts_with($item['article_id'], 'menu-')) {
                    $this->assertMatchesRegularExpression('/\b'.preg_quote($item['id'], '/').'\s*:\s*\{/', $supplementary);
                } else {
                    $this->assertStringContainsString("id:'".$item['article_id']."'", $existing);
                }
            }
        }
        $shipment = collect($groups)->flatMap(fn ($group) => $group['items'])->firstWhere('id', 'shipments');
        $this->assertSame('expeditions', $shipment['article_id']);
        $this->assertSame(route('admin.shipments.index'), $shipment['url']);
    }

    public function test_guide_menu_hides_closed_sections_and_duplicate_product_views_for_managers(): void
    {
        $manager = $this->productManager();
        $response = $this->actingAs($manager)->get('/admin/guide')->assertOk();
        $groups = $response->viewData('guideNavigation');
        $this->assertSame(['catalog', 'help'], array_column($groups, 'id'));
        $items = collect($groups)->flatMap(fn ($group) => $group['items']);
        $this->assertSame(['products', 'guide'], $items->pluck('id')->all());
        $this->assertSame('Produits ASSO', $items->first()['label']);
        $this->assertNotContains('menu-database', $response->viewData('allowedPages'));
        $this->assertNotContains('menu-freight_bookings', $response->viewData('allowedPages'));
    }

    public function test_guide_updates_its_group_order_when_the_menu_changes(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $reordered = array_reverse(config('admin_navigation'));
        config(['admin_navigation' => $reordered]);
        $this->actingAs($admin)->get('/admin/guide')->assertOk()
            ->assertViewHas('guideNavigation', fn ($groups) => array_column($groups, 'id') === array_column($reordered, 'id'));
    }
}
