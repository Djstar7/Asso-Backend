<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Support\AdminNavigation;
use App\Support\ImportHub;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Tests\TestCase;

/** Rubriques « Produits en gros » et « Produits au détail » du back-office. */
class AdminProductSaleSectionsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $platform = User::factory()->create(['email' => 'admin@asso.com']);
        $this->admin = User::factory()->create(['role' => 'admin']);
        $shop = ImportHub::ensureShop();
        $category = Category::create(['name' => 'Mode', 'name_en' => 'Fashion', 'slug' => 'mode']);
        $base = ['user_id' => $platform->id, 'shop_id' => $shop->id, 'category_id' => $category->id, 'price' => 1000, 'stock' => 10, 'status' => 'active'];
        Product::create($base + ['name' => 'Carton de savons', 'slug' => 'carton-savons', 'is_wholesale' => true]);
        Product::create($base + ['name' => 'Robe en pagne', 'slug' => 'robe-pagne', 'is_wholesale' => false]);
    }

    public function test_each_section_lists_only_its_products(): void
    {
        $this->actingAs($this->admin)->get('/admin/products?sale=wholesale')->assertOk()
            ->assertSee('Produits en gros')->assertSee('Carton de savons')->assertDontSee('Robe en pagne')
            ->assertSee('name="sale" value="wholesale"', false)
            ->assertSee(route('admin.products.create', ['sale' => 'wholesale']), false);

        $this->actingAs($this->admin)->get('/admin/products?sale=retail')->assertOk()
            ->assertSee('Produits au détail')->assertSee('Robe en pagne')->assertDontSee('Carton de savons')
            ->assertSee('name="sale" value="retail"', false);

        $this->actingAs($this->admin)->get('/admin/products')->assertOk()
            ->assertSee('Robe en pagne')->assertSee('Carton de savons');
    }

    public function test_creating_from_the_wholesale_section_ticks_wholesale(): void
    {
        $this->actingAs($this->admin)->get('/admin/products/create?sale=wholesale')->assertOk()
            ->assertSee('id="is_wholesale" value="1" checked', false);
        $this->actingAs($this->admin)->get('/admin/products/create')->assertOk()
            ->assertDontSee('id="is_wholesale" value="1" checked', false);
    }

    public function test_menu_highlights_only_the_open_section(): void
    {
        $active = fn (array $query) => collect($this->navigation($query))->flatMap(fn ($g) => $g['items'])
            ->where('active', true)->pluck('id')->all();

        $this->assertSame(['wholesale_products'], $active(['sale' => 'wholesale']));
        $this->assertSame(['retail_products'], $active(['sale' => 'retail']));
        $this->assertSame(['products'], $active([]));
        $this->assertSame(['asso_products'], $active(['asso' => 1]));
    }

    private function navigation(array $query): array
    {
        $request = Request::create('/admin/products', 'GET', $query);
        $request->setRouteResolver(fn () => (new Route('GET', '/admin/products', fn () => null))->name('admin.products.index'));

        return app(AdminNavigation::class)->forUser($this->admin, $request);
    }
}
