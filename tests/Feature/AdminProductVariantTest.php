<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\ImportCountry;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminProductVariantTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_catalog_product_with_variant_stock(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $vendor = User::factory()->create(['role' => 'vendeur']);
        $shop = Shop::create([
            'user_id' => $vendor->id,
            'name' => 'ASSO Import',
            'slug' => 'asso-import',
            'status' => 'active', 'verified_at' => now(),
        ]);
        $category = Category::create(['name' => 'Mode', 'slug' => 'mode']);

        $response = $this->actingAs($admin)->post('/admin/products', $this->productPhoto() + [
            'shop_id' => $shop->id,
            'category_id' => $category->id,
            'name' => 'Basket importée',
            'description' => 'Modèle de démonstration',
            'characteristics' => 'Semelle antidérapante',
            'commercial_information' => 'Garantie 6 mois',
            'price_type' => 'fixed',
            'price' => 25000,
            'type' => 'article',
            'weight' => 0.8,
            'weight_category' => 'X-small',
            'stock' => 0,
            'status' => 'active',
            'variants' => [
                ['attributes' => 'Couleur: Rouge; Pointure: 40', 'sku' => 'RED-40', 'stock' => 4, 'price_adjustment' => 0, 'is_active' => 1],
                ['attributes' => 'Couleur: Bleu; Pointure: 41', 'sku' => 'BLUE-41', 'stock' => 7, 'price_adjustment' => 1500, 'is_active' => 1],
            ],
        ]);

        $response->assertRedirect(route('admin.products.index'));
        $product = Product::where('name', 'Basket importée')->firstOrFail();
        $this->assertSame(11, $product->stock);
        $this->assertSame('Semelle antidérapante', $product->characteristics);
        $this->assertEquals(0.8, (float) $product->weight, 'Le poids d’un article local est conservé (calcul de la livraison).');
        $this->assertCount(2, $product->variants);
        $this->assertSame(['Couleur' => 'Rouge', 'Pointure' => '40'], $product->variants->first()->attributes);

        $this->getJson('/api/v1/products')
            ->assertOk()
            ->assertJsonPath('products.0.description', 'Modèle de démonstration')
            ->assertJsonPath('products.0.characteristics', 'Semelle antidérapante')
            ->assertJsonPath('products.0.commercial_information', 'Garantie 6 mois')
            ->assertJsonPath('products.0.variants.0.attributes.Couleur', 'Rouge')
            ->assertJsonPath('products.0.variants.1.stock', 7);
    }

    public function test_weight_is_required_only_for_china_dubai_and_turkey_products(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $vendor = User::factory()->create(['role' => 'vendeur']);
        $shop = Shop::create([
            'user_id' => $vendor->id,
            'name' => 'Boutique Import',
            'slug' => 'boutique-import',
            'status' => 'active', 'verified_at' => now(),
        ]);
        $category = Category::create(['name' => 'Maison', 'slug' => 'maison']);
        ImportCountry::create(['code' => 'CN', 'name' => 'Chine']);

        $payload = [
            'shop_id' => $shop->id,
            'category_id' => $category->id,
            'name' => 'Produit Chine',
            'price_type' => 'fixed',
            'price' => 15000,
            'type' => 'article',
            'origin_country' => 'CN',
            'stock' => 5,
            'status' => 'active',
        ];

        $this->actingAs($admin)->post('/admin/products', $payload)
            ->assertSessionHasErrors('weight');

        $this->actingAs($admin)->post('/admin/products', $payload + ['weight' => 2.5] + $this->productPhoto())
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.products.index'));

        $this->assertSame('2.5', Product::where('name', 'Produit Chine')->firstOrFail()->weight);
    }
}
