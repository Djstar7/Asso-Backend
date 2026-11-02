<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Conversation;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Audit fiche produit et recherche : boutiques visibles, stock des déclinaisons,
 * recherche par mots, consultation Maritime sans compte, nom « Support ASSO ».
 */
class CatalogAuditFixesTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    private Shop $shop;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seller = User::factory()->create(['role' => 'vendeur']);
        $this->shop = $this->makeShop('Boutique Élégance', now());
        $this->category = Category::create(['name' => 'Vêtements', 'slug' => 'vetements']);
    }

    private function makeShop(string $name, $verifiedAt): Shop
    {
        return Shop::create([
            'user_id' => $this->seller->id,
            'name' => $name,
            'slug' => str($name)->slug().'-'.uniqid(),
            'status' => 'active',
            'verified_at' => $verifiedAt,
        ]);
    }

    private function makeProduct(string $name, array $attributes = []): Product
    {
        return Product::create($attributes + [
            'user_id' => $this->seller->id,
            'shop_id' => $this->shop->id,
            'category_id' => $this->category->id,
            'name' => $name,
            'slug' => str($name)->slug().'-'.uniqid(),
            'description' => 'Description',
            'price' => 1000,
            'stock' => 5,
            'status' => 'active',
        ]);
    }

    private function searchNames(string $search): array
    {
        return collect($this->getJson('/api/v1/products?search='.urlencode($search))
            ->assertOk()->json('products'))->pluck('name')->all();
    }

    public function test_search_finds_every_word_in_any_order_and_any_field(): void
    {
        $this->makeProduct('Robe rouge');
        $this->makeProduct('Pantalon bleu');

        $this->assertSame(['Robe rouge'], $this->searchNames('rouge robe'));
        $this->assertSame([], $this->searchNames('rouge pantalon'));
        // Boutique et catégorie font partie de la recherche.
        $this->assertCount(2, $this->searchNames('boutique'));
    }

    public function test_synonyms_apply_whatever_the_case_of_the_accented_word(): void
    {
        \App\Models\SearchSynonym::create(['term' => 'téléphone', 'synonym' => 'smartphone', 'weight' => 1, 'is_active' => true]);
        $this->makeProduct('Smartphone Android');

        $this->assertSame(['Smartphone Android'], $this->searchNames('TÉLÉPHONE'));
    }

    public function test_search_treats_wildcards_as_plain_text(): void
    {
        $this->makeProduct('Coton 100% bio');
        $this->makeProduct('Coton 100 bio');

        $this->assertSame(['Coton 100% bio'], $this->searchNames('100%'));
    }

    public function test_search_ranks_the_exact_name_first(): void
    {
        $this->makeProduct('Sac en cuir', ['description' => 'Montre offerte']);
        $this->makeProduct('Montre');
        $this->makeProduct('Montre de sport');

        $this->assertSame(['Montre', 'Montre de sport', 'Sac en cuir'], $this->searchNames('montre'));
    }

    public function test_an_unknown_sort_column_no_longer_fails(): void
    {
        $this->makeProduct('Robe');

        $this->getJson('/api/v1/products?sort_by=relevance')->assertOk();
        $this->getJson('/api/v1/products?sort_by=inconnue')->assertOk();
    }

    public function test_products_of_an_unverified_shop_are_hidden(): void
    {
        $hidden = $this->makeProduct('Robe cachée', ['shop_id' => $this->makeShop('Non vérifiée', null)->id]);
        $visible = $this->makeProduct('Robe visible');

        $this->assertSame(['Robe visible'], $this->searchNames('robe'));
        $this->getJson("/api/v1/products/{$hidden->id}")->assertNotFound();
        $this->getJson("/api/v1/products/{$visible->id}")->assertOk();
    }

    public function test_shop_page_products_carry_their_shop_and_available_stock(): void
    {
        $product = $this->makeProduct('Robe', ['stock' => 0]);
        $product->variants()->create(['attributes' => ['Taille' => 'M'], 'stock' => 4, 'is_active' => true]);

        $response = $this->getJson("/api/v1/shops/{$this->shop->id}")->assertOk();
        $item = collect(data_get($response->json(), 'shop.products', data_get($response->json(), 'data.products')))->first();

        $this->assertSame($this->shop->id, $item['shop']['id']);
        $this->assertSame($this->seller->id, $item['seller']['id']);
        $this->assertSame(4, $item['stock']);
    }

    public function test_active_variants_decide_the_available_stock(): void
    {
        $product = $this->makeProduct('Robe', ['stock' => 0]);
        $product->variants()->create(['attributes' => ['Taille' => 'M'], 'stock' => 4, 'is_active' => true]);
        $product->variants()->create(['attributes' => ['Taille' => 'L'], 'stock' => 9, 'is_active' => false]);

        $this->getJson("/api/v1/products/{$product->id}")->assertOk()->assertJsonPath('product.stock', 4);
    }

    public function test_vendor_cannot_overwrite_the_stock_of_a_product_with_variants(): void
    {
        $product = $this->makeProduct('Robe');
        $product->variants()->create(['attributes' => ['Taille' => 'M'], 'stock' => 4, 'is_active' => true]);
        Sanctum::actingAs($this->seller);

        $response = $this->postJson("/api/v1/vendor/products/{$product->id}/stock", ['stock' => 50, 'quantity' => 50, 'type' => 'adjustment']);

        $response->assertStatus(422);
        $this->assertSame(__('products.stock_managed_by_variants'), $response->json('message'));
    }

    public function test_guest_can_browse_maritime_departures_but_not_book(): void
    {
        $this->getJson('/api/v1/maritime/departures')->assertOk();
        $this->postJson('/api/v1/maritime/departures/1/book')->assertUnauthorized();
    }

    public function test_admin_accounts_are_presented_as_asso_support(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'first_name' => 'Admin', 'last_name' => 'Principal']);
        $client = User::factory()->create(['role' => 'client']);
        Conversation::create(['user1_id' => $client->id, 'user2_id' => $admin->id, 'last_message_at' => now()]);
        Sanctum::actingAs($client);

        $this->getJson('/api/v1/conversations')->assertOk()
            ->assertJsonPath('conversations.0.other_user.name', 'Support ASSO');
        $this->assertSame('ASSO Support', $admin->publicName('en'));
        $this->assertSame($this->seller->name, $this->seller->publicName());
    }
}
