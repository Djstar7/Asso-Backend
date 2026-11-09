<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Services\ProductVariantService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProductVariantOptionsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $vendor;
    private Shop $shop;
    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->vendor = User::factory()->create(['role' => 'vendeur']);
        $this->shop = Shop::create([
            'user_id' => $this->vendor->id,
            'name' => 'Boutique locale',
            'slug' => 'boutique-locale',
            'status' => 'active', 'verified_at' => now(),
        ]);
        $this->category = Category::create(['name' => 'Chaussures', 'slug' => 'chaussures']);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'shop_id' => $this->shop->id,
            'category_id' => $this->category->id,
            'name' => 'Basket locale',
            'price_type' => 'fixed',
            'price' => 20000,
            'type' => 'article',
            'weight' => 0.8, // P4 : poids obligatoire pour un article
            'stock' => 0,
            'status' => 'active',
            'variant_options' => json_encode([
                ['name' => 'Couleur', 'type' => 'color', 'values' => [
                    ['value' => 'Noir', 'hex' => '#111111'],
                    ['value' => 'Bleu nuit', 'hex' => '#123456'],
                ]],
                ['name' => 'Pointure', 'type' => 'text', 'values' => [['value' => '41'], ['value' => '42']]],
            ]),
            'variants' => [
                ['attributes' => ['Couleur' => 'Noir', 'Pointure' => '41'], 'stock' => 3, 'price_adjustment' => 0, 'sku' => 'N-41', 'is_active' => 1],
                ['attributes' => ['Couleur' => 'Noir', 'Pointure' => '42'], 'stock' => 0, 'price_adjustment' => 0, 'sku' => '', 'is_active' => 1],
                ['attributes' => ['Couleur' => 'Bleu nuit', 'Pointure' => '41'], 'stock' => 2, 'price_adjustment' => 1500, 'sku' => '', 'is_active' => 1],
                ['attributes' => ['Couleur' => 'Bleu nuit', 'Pointure' => '42'], 'stock' => 4, 'price_adjustment' => 1500, 'sku' => '', 'is_active' => 0],
            ],
        ], $overrides);
    }

    public function test_product_dimensions_are_saved_updated_and_exposed(): void
    {
        $this->actingAs($this->admin)->post('/admin/products', $this->payload([
            'length_cm' => '120.50', 'width_cm' => '100',
        ]))->assertSessionHasNoErrors();

        $product = Product::where('name', 'Basket locale')->firstOrFail();
        $this->assertSame('120.50', $product->length_cm);
        $this->getJson("/api/v1/products/{$product->id}")
            ->assertJsonPath('product.length_cm', 120.5)
            ->assertJsonPath('product.width_cm', 100);

        Sanctum::actingAs($this->vendor);
        $this->putJson("/api/v1/vendor/products/{$product->id}", ['width_cm' => 90])
            ->assertOk()->assertJsonPath('product.width_cm', 90);
        $this->assertSame('120.50', $product->fresh()->length_cm);
        $this->putJson("/api/v1/vendor/products/{$product->id}", ['length_cm' => -1])
            ->assertUnprocessable()->assertJsonValidationErrors('length_cm');
        $this->putJson("/api/v1/vendor/products/{$product->id}", ['length_cm' => null, 'width_cm' => null])
            ->assertOk()->assertJsonPath('product.length_cm', null);
        $this->assertNull($product->fresh()->width_cm);
    }

    public function test_admin_creates_local_product_with_color_and_size_matrix(): void
    {
        $this->actingAs($this->admin)->post('/admin/products', $this->payload())
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.products.index'));

        $product = Product::where('name', 'Basket locale')->firstOrFail();
        // La déclinaison masquée (4) ne compte pas : elle n'est pas commandable.
        $this->assertSame(5, $product->stock);
        $this->assertCount(4, $product->variants);
        $this->assertSame('#123456', $product->variant_options[0]['values'][1]['hex']);

        // Produit local : visible dans le catalogue standard, avec les pastilles et sans la variante masquée.
        $response = $this->getJson("/api/v1/products/{$product->id}")->assertOk();
        $data = $response->json('product') ?? $response->json('data') ?? $response->json();
        $this->assertCount(3, $data['variants']);
        $this->assertSame('Couleur', $data['variant_options'][0]['name']);
        $this->assertSame('color', $data['variant_options'][0]['type']);
        $this->assertSame(['Noir', 'Bleu nuit'], array_column($data['variant_options'][0]['values'], 'value'));
        $this->assertSame(['41', '42'], array_column($data['variant_options'][1]['values'], 'value'));
        $this->assertEquals(21500, collect($data['variants'])->firstWhere('attributes.Couleur', 'Bleu nuit')['price']);
    }

    public function test_updating_variants_keeps_ids_of_existing_combinations(): void
    {
        $this->actingAs($this->admin)->post('/admin/products', $this->payload());
        $product = Product::where('name', 'Basket locale')->firstOrFail();
        $blackId = $product->variants->first(fn ($v) => $v->attributes === ['Couleur' => 'Noir', 'Pointure' => '41'])->id;

        $payload = $this->payload();
        $payload['variants'][0]['stock'] = 10;
        unset($payload['variants'][3]); // combinaison retirée

        $this->actingAs($this->admin)->put("/admin/products/{$product->id}", $payload)
            ->assertSessionHasNoErrors();

        $product->refresh();
        $this->assertCount(3, $product->variants);
        $this->assertSame(10, $product->variants->firstWhere('id', $blackId)->stock);
        $this->assertSame(12, $product->stock);
    }

    public function test_admin_create_and_edit_pages_render_variant_and_image_builders(): void
    {
        $this->actingAs($this->admin)->get('/admin/products/create')
            ->assertOk()
            ->assertSee('variant_builder', false)
            ->assertSee('im_dropzone', false);

        $this->actingAs($this->admin)->post('/admin/products', $this->payload());
        $product = Product::where('name', 'Basket locale')->firstOrFail();

        $this->actingAs($this->admin)->get("/admin/products/{$product->id}/edit")
            ->assertOk()
            ->assertSee('Bleu nuit')
            ->assertSee('#123456');
        $this->actingAs($this->admin)->get("/admin/products/{$product->id}")
            ->assertOk()
            ->assertSee('Choix proposés au client');
    }

    public function test_vendor_mobile_format_and_clearing_variants(): void
    {
        $product = Product::create([
            'user_id' => $this->vendor->id,
            'shop_id' => $this->shop->id,
            'category_id' => $this->category->id,
            'name' => 'Téléphone',
            'price' => 100000,
            'stock' => 1,
            'status' => 'active',
        ]);
        Sanctum::actingAs($this->vendor);

        $this->postJson("/api/v1/vendor/products/{$product->id}", [
            '_method' => 'PUT',
            'variants' => [
                ['attributes' => ['couleur' => 'Noir', 'Stockage' => '128 Go'], 'stock' => 2],
                ['attributes' => ['couleur' => 'Noir', 'Stockage' => '256 Go'], 'stock' => 1, 'price_adjustment' => 25000],
            ],
        ])->assertOk()
            ->assertJsonPath('product.variant_options.0.name', 'Couleur')
            ->assertJsonPath('product.variant_options.0.values.0.hex', '#212121');

        $this->assertSame(3, $product->fresh()->stock);

        $this->postJson("/api/v1/vendor/products/{$product->id}", ['_method' => 'PUT', 'replace_variants' => 1])
            ->assertOk();
        $this->assertCount(0, $product->fresh()->variants);
    }

    public function test_attribute_formats_are_normalized(): void
    {
        $service = app(ProductVariantService::class);
        $this->assertSame(['Couleur' => 'Rouge', 'Taille' => 'M'], $service->normalizeAttributes('Couleur: Rouge; Taille: M'));
        $this->assertSame(['Couleur' => 'Rouge'], $service->normalizeAttributes(null, 'couleur', 'Rouge'));
        $this->assertSame(['Pointure' => '42'], $service->normalizeAttributes(['pointure' => ' 42 ']));
        $this->assertSame('#1A237E', ProductVariantService::guessHex('Bleu marine'));
    }
}
