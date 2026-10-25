<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\AdminNavigation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Tests\TestCase;

class AdminNavigationTest extends TestCase
{
    use RefreshDatabase;

    private function navigation(User $user, string $name, array $query = [], array $counts = []): array
    {
        $request = Request::create('/admin/test', 'GET', $query);
        $request->setRouteResolver(fn () => (new Route('GET', '/admin/test', fn () => null))->name($name));

        return app(AdminNavigation::class)->forUser($user, $request, $counts);
    }

    private function items(array $navigation): \Illuminate\Support\Collection
    {
        return collect($navigation)->flatMap(fn ($group) => $group['items']);
    }

    public function test_all_menu_destinations_exist_are_get_routes_and_have_both_translations(): void
    {
        foreach (['fr', 'en'] as $locale) {
            $translations = \Illuminate\Support\Arr::dot(json_decode(file_get_contents(lang_path($locale.'.json')), true)['admin_nav']);
            foreach ($translations as $key => $value) {
                $this->assertSame($value, __('admin_nav.'.$key, [], $locale));
            }
        }
        $ids = [];
        foreach (config('admin_navigation') as $group) {
            foreach (['fr', 'en'] as $locale) {
                $key = 'admin_nav.groups.'.$group['id'];
                $this->assertNotSame($key, __($key, [], $locale));
            }
            foreach ($group['items'] as $item) {
                $this->assertNotContains($item['id'], $ids);
                $ids[] = $item['id'];
                $route = app('router')->getRoutes()->getByName($item['route']);
                $this->assertNotNull($route, $item['route']);
                $this->assertContains('GET', $route->methods());
                foreach (['fr', 'en'] as $locale) {
                    $key = 'admin_nav.items.'.$item['id'];
                    $this->assertNotSame($key, __($key, [], $locale));
                }
            }
        }
    }

    public function test_product_and_finance_pages_have_exactly_one_selected_entry(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        foreach ([
            ['admin.products.index', [], 'products'],
            ['admin.products.index', ['asso' => 1], 'asso_products'],
            ['admin.products.edit', ['asso' => 1], 'asso_products'],
            ['admin.revenue.index', [], 'revenue'],
            ['admin.revenue.withdrawal', [], 'withdrawals'],
            ['admin.settings.categories', [], 'categories'],
            ['admin.settings.payments', [], 'payment_settings'],
            ['admin.maritime.bookings.show', [], 'freight_bookings'],
            ['admin.maritime.partners.show', [], 'freight_partners'],
        ] as [$route, $query, $expected]) {
            $active = $this->items($this->navigation($admin, $route, $query))->where('active', true)->pluck('id')->values()->all();
            $this->assertSame([$expected], $active, $route);
        }
    }

    public function test_international_shipments_share_one_menu_entry_and_old_filtered_url_still_works(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $response = $this->actingAs($admin)->get('/admin/shipments?scope=export&status=all')->assertOk()
            ->assertSee('Exports internationaux');
        $this->assertStringNotContainsString('Commandes internationales', strip_tags($response->getContent()));
        $items = $this->items($this->navigation($admin, 'admin.shipments.index', ['scope' => 'export']));
        $this->assertSame(['shipments'], $items->where('active', true)->pluck('id')->values()->all());
        $this->assertSame(1, $items->filter(fn ($item) => str_contains($item['url'], '/admin/shipments'))->count());
    }

    public function test_manager_sees_only_authorized_entries_without_empty_sections(): void
    {
        $manager = User::factory()->create(['role' => 'product_manager', 'roles' => ['product_manager'], 'admin_permissions' => ['shipments']]);
        $navigation = $this->navigation($manager, 'admin.shipments.index');
        $this->assertSame(['catalog', 'delivery', 'help'], array_column($navigation, 'id'));
        $items = $this->items($navigation);
        $this->assertSame(['products', 'shipments', 'guide'], $items->pluck('id')->all());
        $this->assertFalse($manager->canAccessAdminRoute('admin.settings.index'));
        $this->actingAs($manager)->get('/admin/products')->assertOk()->assertSee('data-nav-item="products"', false)
            ->assertDontSee('data-nav-group="administration"', false)->assertDontSee('data-nav-item="asso_products"', false);
        $this->get('/admin/settings')->assertForbidden();
    }

    public function test_badges_follow_their_entries_and_the_selected_section_opens_without_javascript(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);
        $navigation = $this->navigation($admin, 'admin.asso-orders.index', [], ['assoToConfirmCount' => 7, 'newDisputesCount' => 2]);
        $items = $this->items($navigation)->keyBy('id');
        $this->assertSame(7, $items['asso_orders']['badge']);
        $this->assertSame(2, $items['disputes']['badge']);
        $this->assertSame('danger', $items['disputes']['tone']);
        $html = view('admin.partials.navigation', ['navigation' => $navigation, 'navigationLocale' => 'fr'])->render();
        $this->assertMatchesRegularExpression('/data-nav-group="orders"[^>]*\sopen\s*>/s', $html);
        $this->assertSame(1, substr_count($html, 'aria-current="page"'));
    }

    public function test_english_menu_follows_account_language_including_search_labels(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'locale' => 'en', 'first_name' => 'Équipe']);
        $this->actingAs($admin)->get('/admin/products')->assertOk()
            ->assertSee('Catalog and shops')->assertSee('Search the menu')->assertSee('Shipment tracking')
            ->assertDontSee('admin_nav.')->assertSee('>É</span>', false);
    }
}
