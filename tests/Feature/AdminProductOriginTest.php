<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\ImportCountry;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Vérifie que le formulaire admin de produit permet de rattacher un produit
 * à un pays d'importation (Chine/Turquie/Dubaï).
 */
class AdminProductOriginTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function shopAndCategory(): array
    {
        $vendor = User::factory()->create(['role' => 'vendeur']);
        $shop = Shop::create([
            'user_id' => $vendor->id, 'name' => 'Boutique', 'slug' => 'boutique-' . $vendor->id, 'status' => 'active',
        ]);
        $cat = Category::create(['name' => 'Électronique', 'slug' => 'electro']);

        return [$shop, $cat];
    }

    public function test_create_page_shows_country_selector(): void
    {
        ImportCountry::create(['code' => 'CN', 'name' => 'Chine', 'flag' => '🇨🇳', 'sort_order' => 1]);

        $res = $this->actingAs($this->admin())->get('/admin/products/create');

        $res->assertOk();
        $res->assertSee('Pays d\'origine', false);
        $res->assertSee('Chine');
    }

    public function test_admin_can_create_product_linked_to_china(): void
    {
        ImportCountry::create(['code' => 'CN', 'name' => 'Chine', 'flag' => '🇨🇳', 'sort_order' => 1]);
        [$shop, $cat] = $this->shopAndCategory();

        $res = $this->actingAs($this->admin())->post('/admin/products', [
            'shop_id' => $shop->id,
            'category_id' => $cat->id,
            'name' => 'Montre importée',
            'price_type' => 'fixed',
            'price' => 15000,
            'type' => 'article',
            'origin_country' => 'CN',
            'stock' => 5,
            'status' => 'active',
        ]);

        $res->assertRedirect(route('admin.products.index'));
        $this->assertDatabaseHas('products', ['name' => 'Montre importée', 'origin_country' => 'CN']);
    }

    public function test_product_without_country_is_local(): void
    {
        [$shop, $cat] = $this->shopAndCategory();

        $this->actingAs($this->admin())->post('/admin/products', [
            'shop_id' => $shop->id,
            'category_id' => $cat->id,
            'name' => 'Produit local',
            'price_type' => 'fixed',
            'price' => 8000,
            'type' => 'article',
            'origin_country' => '',
            'stock' => 3,
            'status' => 'active',
        ]);

        $product = Product::where('name', 'Produit local')->first();
        $this->assertNotNull($product);
        $this->assertNull($product->origin_country);
    }

    public function test_invalid_country_is_rejected(): void
    {
        [$shop, $cat] = $this->shopAndCategory();

        $res = $this->actingAs($this->admin())->post('/admin/products', [
            'shop_id' => $shop->id,
            'category_id' => $cat->id,
            'name' => 'Produit test',
            'price_type' => 'fixed',
            'price' => 8000,
            'type' => 'article',
            'origin_country' => 'ZZ', // pays inexistant
            'stock' => 3,
            'status' => 'active',
        ]);

        $res->assertSessionHasErrors('origin_country');
        $this->assertDatabaseMissing('products', ['name' => 'Produit test']);
    }
}
