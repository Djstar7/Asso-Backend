<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductBook;
use App\Models\Shop;
use App\Models\Subcategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LibraryTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $vendor;
    private Shop $shop;
    private Category $shelf;
    private Category $general;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->vendor = User::factory()->create(['role' => 'vendeur']);
        $this->shop = Shop::create([
            'user_id' => $this->vendor->id, 'name' => 'Librairie du Centre', 'slug' => 'librairie-du-centre',
            'status' => 'active', 'verified_at' => now(),
        ]);
        $this->shelf = Category::create(['name' => 'Romans', 'name_en' => 'Novels', 'slug' => 'romans', 'is_library' => true]);
        $this->general = Category::create(['name' => 'Chaussures', 'name_en' => 'Shoes', 'slug' => 'chaussures']);
    }

    private function product(Category $category, array $overrides = []): Product
    {
        return Product::create($overrides + [
            'shop_id' => $this->shop->id, 'user_id' => $this->vendor->id, 'category_id' => $category->id,
            'name' => 'Article ' . uniqid(), 'slug' => 'article-' . uniqid(), 'description' => 'Description',
            'price' => 5000, 'currency' => 'XAF', 'price_type' => 'fixed', 'type' => 'article',
            'weight' => 0.4, 'weight_category' => 'X-small', 'stock' => 5, 'status' => 'active',
        ]);
    }

    private function book(array $book = [], array $product = []): Product
    {
        $item = $this->product($this->shelf, $product + ['name' => 'Une si longue lettre']);
        $item->book()->create($book + ['author' => 'Mariama Bâ', 'language' => 'fr']);

        return $item;
    }

    private function adminPayload(array $overrides = []): array
    {
        return array_merge([
            'shop_id' => $this->shop->id, 'category_id' => $this->shelf->id, 'name' => 'Things Fall Apart',
            'price_type' => 'fixed', 'price' => 6000, 'type' => 'article', 'weight' => 0.3,
            'stock' => 4, 'status' => 'active',
            'book' => ['author' => 'Chinua Achebe', 'isbn' => '978-0-385-47454-2', 'language' => 'en', 'format' => 'paperback', 'pages' => 209],
        ], $overrides);
    }

    public function test_admin_sees_every_library_page(): void
    {
        $this->book();

        foreach (['admin.library.overview', 'admin.library.books.index', 'admin.library.import.create', 'admin.library.shelves.index'] as $route) {
            $this->actingAs($this->admin)->get(route($route))->assertOk()->assertSee('Bibliothèque', false);
        }
        $this->actingAs($this->admin)->get(route('admin.products.create', ['library' => 1]))
            ->assertOk()->assertSee('Détails du livre')->assertSee('Romans')->assertDontSee('Chaussures');
    }

    public function test_library_section_is_reserved_to_admin(): void
    {
        $manager = User::factory()->create(['role' => 'product_manager']);
        $book = $this->book();

        $this->actingAs($manager)->get(route('admin.library.books.index'))->assertForbidden();
        $this->actingAs($manager)->get(route('admin.products.create', ['library' => 1]))->assertForbidden();
        // 404 quand AssoScope masque déjà le produit (boutique non ASSO), 403 sinon.
        $this->assertContains($this->actingAs($manager)->get(route('admin.products.edit', $book))->status(), [403, 404]);
        $this->actingAs($manager)->post(route('admin.products.store'), $this->adminPayload())->assertForbidden();
        $this->assertDatabaseCount('products', 1);
    }

    public function test_admin_creates_and_updates_a_book_through_the_product_form(): void
    {
        $this->actingAs($this->admin)->post(route('admin.products.store'), $this->adminPayload())
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.library.books.index'));

        $product = Product::where('name', 'Things Fall Apart')->firstOrFail();
        $this->assertSame('Chinua Achebe', $product->book->author);
        $this->assertSame('9780385474542', $product->book->isbn);

        $this->actingAs($this->admin)->put(route('admin.products.update', $product), $this->adminPayload([
            'book' => ['author' => 'Chinua Achebe', 'isbn' => '9780385474542', 'language' => 'en', 'format' => 'hardcover'],
        ]))->assertSessionHasNoErrors()->assertRedirect(route('admin.library.books.index'));

        $this->assertSame('hardcover', $product->book->fresh()->format);
    }

    public function test_author_is_required_for_a_book(): void
    {
        $this->actingAs($this->admin)->post(route('admin.products.store'), $this->adminPayload(['book' => ['author' => '']]))
            ->assertSessionHasErrors('book.author');
    }

    public function test_moving_a_book_out_of_the_library_drops_its_sheet(): void
    {
        $book = $this->book();

        $this->actingAs($this->admin)->put(route('admin.products.update', $book), $this->adminPayload([
            'category_id' => $this->general->id, 'name' => $book->name, 'book' => null,
        ]))->assertSessionHasNoErrors();

        $this->assertNull($book->fresh()->book);
    }

    public function test_general_admin_catalog_and_categories_exclude_books(): void
    {
        $this->book();
        $this->product($this->general, ['name' => 'Basket locale']);

        $this->actingAs($this->admin)->get(route('admin.products.index'))
            ->assertOk()->assertSee('Basket locale')->assertDontSee('Une si longue lettre');
        $this->actingAs($this->admin)->get(route('admin.settings.categories'))
            ->assertOk()->assertSee('Chaussures')->assertDontSee('Romans');
        $this->actingAs($this->admin)->get(route('admin.library.books.index'))
            ->assertOk()->assertSee('Une si longue lettre')->assertDontSee('Basket locale');
    }

    public function test_books_filters_find_by_author_isbn_and_missing_sheet(): void
    {
        $this->book(['isbn' => '9782708703476']);
        $this->product($this->shelf, ['name' => 'Livre sans fiche']);

        $this->actingAs($this->admin)->get(route('admin.library.books.index', ['search' => 'mariama']))
            ->assertSee('Une si longue lettre')->assertDontSee('Livre sans fiche');
        $this->actingAs($this->admin)->get(route('admin.library.books.index', ['search' => '978-2708']))
            ->assertSee('Une si longue lettre');
        $this->actingAs($this->admin)->get(route('admin.library.books.index', ['issue' => 'no_sheet']))
            ->assertSee('Livre sans fiche')->assertDontSee('Une si longue lettre');
    }

    public function test_import_creates_then_updates_books_by_isbn_and_reports_errors(): void
    {
        Subcategory::create(['category_id' => $this->shelf->id, 'name' => 'Classiques', 'name_en' => 'Classics', 'slug' => 'classiques']);
        $csv = "titre;auteur;prix;rayon;genre;isbn;editeur;langue;format;stock;poids\n"
            . "Une si longue lettre;Mariama Bâ;5000;Romans;Classiques;978-2-7087-0347-6;NEA;fr;paperback;20;0,3\n"
            . "Sans auteur;;3000;Romans;;;;fr;;1;\n"
            . "Mauvais rayon;Inconnu;3000;Cuisine;;;;fr;;1;\n";

        $this->actingAs($this->admin)->post(route('admin.library.import.store'), [
            'shop_id' => $this->shop->id,
            'file' => UploadedFile::fake()->createWithContent('livres.csv', $csv),
        ])->assertRedirect(route('admin.library.import.create'))
            ->assertSessionHas('import_report', fn ($r) => $r['created'] === 1 && $r['updated'] === 0 && count($r['errors']) === 2);

        $product = Product::where('name', 'Une si longue lettre')->firstOrFail();
        $this->assertSame($this->shop->user_id, $product->user_id);
        $this->assertEquals(0.3, (float) $product->weight);
        $this->assertSame('Classiques', $product->subcategory->name);
        $this->assertSame('9782708703476', $product->book->isbn);

        $update = "titre,auteur,prix,rayon,isbn,stock\nUne si longue lettre,Mariama Bâ,4500,romans,9782708703476,8\n";
        $this->actingAs($this->admin)->post(route('admin.library.import.store'), [
            'shop_id' => $this->shop->id,
            'file' => UploadedFile::fake()->createWithContent('maj.csv', $update),
        ])->assertSessionHas('import_report', fn ($r) => $r['created'] === 0 && $r['updated'] === 1);

        $this->assertSame(1, ProductBook::count());
        $this->assertEquals(4500, (float) $product->fresh()->price);
        $this->assertSame(8, $product->fresh()->stock);
        $this->assertEquals(0.3, (float) $product->fresh()->weight);
        // Colonnes absentes du fichier de mise à jour : rien n'est effacé.
        $this->assertSame('Classiques', $product->fresh()->subcategory?->name);
        $this->assertSame('NEA', $product->book->fresh()->publisher);
        $this->assertSame('paperback', $product->book->fresh()->format);
    }

    public function test_shelves_and_genres_are_managed_in_the_section(): void
    {
        $this->actingAs($this->admin)->post(route('admin.library.shelves.store'), ['name' => 'Jeunesse', 'name_en' => 'Children'])
            ->assertSessionHasNoErrors();
        $shelf = Category::where('name', 'Jeunesse')->firstOrFail();
        $this->assertTrue($shelf->is_library);

        $this->actingAs($this->admin)->post(route('admin.library.genres.store', $shelf), ['name' => 'Contes', 'name_en' => 'Tales'])
            ->assertSessionHasNoErrors();
        $this->assertSame(['Contes'], $shelf->subcategories()->pluck('name')->all());

        // Une catégorie du catalogue général ne passe pas par ces routes.
        $this->actingAs($this->admin)->delete(route('admin.library.shelves.destroy', $this->general))->assertNotFound();

        // Rayon occupé : suppression refusée.
        $this->book();
        $this->actingAs($this->admin)->delete(route('admin.library.shelves.destroy', $this->shelf))->assertSessionHas('error');
        $this->assertNotNull($this->shelf->fresh());
    }

    public function test_api_keeps_books_out_of_general_feeds_but_findable(): void
    {
        $book = $this->book(['isbn' => '9782708703476']);
        $this->product($this->general, ['name' => 'Basket locale']);
        $names = fn ($response) => collect($response->json('products'))->pluck('name')->all();

        $this->assertNotContains($book->name, $names($this->getJson('/api/v1/products')->assertOk()));
        $this->assertNotContains($book->name, $names($this->getJson('/api/v1/products/recent')->assertOk()));
        $this->assertSame([$book->name], $names($this->getJson('/api/v1/products?library=1')->assertOk()));
        $this->assertContains($book->name, $names($this->getJson('/api/v1/products?search=mariama')->assertOk()));
        $this->assertContains($book->name, $names($this->getJson('/api/v1/products?shop_id=' . $this->shop->id)->assertOk()));

        $detail = $this->getJson("/api/v1/products/{$book->id}")->assertOk();
        $this->assertSame('Mariama Bâ', data_get($detail->json(), 'product.book.author') ?? data_get($detail->json(), 'data.book.author'));
    }

    public function test_api_categories_separate_shelves(): void
    {
        $names = fn ($query) => collect($this->getJson('/api/v1/categories' . $query)->assertOk()->json('categories'))->pluck('name')->all();

        $this->assertSame(['Chaussures'], $names(''));
        $this->assertSame(['Romans'], $names('?library=1'));
        $this->assertEqualsCanonicalizing(['Chaussures', 'Romans'], $names('?with_library=1'));
    }

    public function test_vendor_updates_price_without_resending_the_book_sheet(): void
    {
        $book = $this->book();
        Sanctum::actingAs($this->vendor);

        $this->putJson("/api/v1/vendor/products/{$book->id}", ['price' => 4000])->assertOk();
        $this->assertSame('Mariama Bâ', $book->fresh()->book->author);

        $this->putJson("/api/v1/vendor/products/{$book->id}", ['book' => ['author' => 'Mariama Ba', 'format' => 'ebook']])
            ->assertOk()->assertJsonPath('product.book.format', 'ebook');

        $this->putJson("/api/v1/vendor/products/{$book->id}", ['book' => ['author' => '']])
            ->assertUnprocessable()->assertJsonValidationErrors('book.author');
    }
}
