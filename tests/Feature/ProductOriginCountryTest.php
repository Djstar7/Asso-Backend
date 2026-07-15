<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Vérifie la gestion des produits importés par pays (Turquie/Chine/Dubaï) :
 *  - filtrage public par origin_country
 *  - présence du champ origin_country dans les réponses JSON (bug corrigé)
 */
class ProductOriginCountryTest extends TestCase
{
    use RefreshDatabase;

    private function makeVendorWithShop(): array
    {
        $user = User::factory()->create();
        $shop = Shop::create([
            'user_id' => $user->id,
            'name' => 'Boutique Test',
            'slug' => 'boutique-test-' . $user->id,
            'status' => 'active',
        ]);

        return [$user, $shop];
    }

    private function makeProduct(User $user, Shop $shop, Category $cat, string $name, ?string $originCountry): Product
    {
        return Product::create([
            'user_id' => $user->id,
            'shop_id' => $shop->id,
            'category_id' => $cat->id,
            'name' => $name,
            'slug' => \Illuminate\Support\Str::slug($name) . '-' . uniqid(),
            'price' => 15000,
            'stock' => 10,
            'status' => 'active',
            'origin_country' => $originCountry,
        ]);
    }

    public function test_public_listing_filters_by_origin_country_and_exposes_field(): void
    {
        [$user, $shop] = $this->makeVendorWithShop();
        $cat = Category::create(['name' => 'Électronique', 'slug' => 'electronique']);

        $turkey = $this->makeProduct($user, $shop, $cat, 'Veste Turquie', 'TR');
        $this->makeProduct($user, $shop, $cat, 'Produit Local', null);

        // Filtre Turquie : ne doit renvoyer que le produit TR, avec le champ origin_country
        $res = $this->getJson('/api/v1/products?origin_country=TR');
        $res->assertOk();

        $products = $res->json('products');
        $this->assertCount(1, $products, 'Seul le produit TR doit être renvoyé');
        $this->assertSame($turkey->id, $products[0]['id']);
        $this->assertArrayHasKey('origin_country', $products[0], 'origin_country doit être exposé');
        $this->assertSame('TR', $products[0]['origin_country']);
    }

    public function test_origin_country_filter_is_case_insensitive(): void
    {
        [$user, $shop] = $this->makeVendorWithShop();
        $cat = Category::create(['name' => 'Mode', 'slug' => 'mode']);
        $this->makeProduct($user, $shop, $cat, 'Sac Dubaï', 'AE');

        $res = $this->getJson('/api/v1/products?origin_country=ae');
        $res->assertOk();
        $this->assertCount(1, $res->json('products'));
        $this->assertSame('AE', $res->json('products.0.origin_country'));
    }

    public function test_vendor_products_expose_origin_country_for_edit(): void
    {
        [$user, $shop] = $this->makeVendorWithShop();
        $cat = Category::create(['name' => 'Maison', 'slug' => 'maison']);
        $this->makeProduct($user, $shop, $cat, 'Lampe Chine', 'CN');

        Sanctum::actingAs($user);
        $res = $this->getJson('/api/v1/vendor/products');
        $res->assertOk();

        // Le champ doit être présent pour permettre le pré-remplissage en édition
        $products = $res->json('products') ?? $res->json('data') ?? [];
        $this->assertNotEmpty($products, 'Le vendeur doit voir ses produits');
        $found = collect($products)->firstWhere('name', 'Lampe Chine');
        $this->assertNotNull($found);
        $this->assertArrayHasKey('origin_country', $found);
        $this->assertSame('CN', $found['origin_country']);
    }
}
