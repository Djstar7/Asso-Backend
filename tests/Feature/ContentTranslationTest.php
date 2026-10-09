<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\LegalPage;
use App\Models\Package;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Shop;
use App\Models\Subcategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ContentTranslationTest extends TestCase
{
    use RefreshDatabase;

    private function category(): Category
    {
        $category = Category::create([
            'name' => 'Électronique', 'name_en' => 'Electronics', 'slug' => 'electronique',
            'description' => 'Appareils électroniques',
        ]);
        Subcategory::create(['category_id' => $category->id, 'name' => 'Téléphones', 'name_en' => 'Phones', 'slug' => 'telephones']);

        return $category;
    }

    private function package(): Package
    {
        return Package::create([
            'type' => 'storage', 'name' => 'Stockage Starter', 'description' => 'Pour démarrer',
            'price' => 1000, 'duration_days' => 30, 'storage_size_mb' => 300,
            'benefits' => ['300 Mo', 'Support'], 'is_active' => true, 'order' => 1,
        ]);
    }

    public function test_categories_follow_the_request_language_and_fall_back_to_french(): void
    {
        $this->category()->setTranslation('description', 'en', 'Electronic devices');

        $this->withHeader('Accept-Language', 'en')->getJson('/api/v1/categories')
            ->assertOk()
            ->assertJsonPath('categories.0.name', 'Electronics')
            ->assertJsonPath('categories.0.name_en', 'Electronics')
            ->assertJsonPath('categories.0.description', 'Electronic devices')
            ->assertJsonPath('categories.0.subcategories.0.name', 'Phones');

        $this->withHeader('Accept-Language', 'fr')->getJson('/api/v1/categories')
            ->assertJsonPath('categories.0.name', 'Électronique')
            ->assertJsonPath('categories.0.description', 'Appareils électroniques');
    }

    public function test_package_text_and_benefits_are_translated_with_french_fallback(): void
    {
        $package = $this->package();
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->withHeader('Accept-Language', 'en')->getJson('/api/v1/packages')
            ->assertJsonPath('packages.0.name', 'Stockage Starter')
            ->assertJsonPath('packages.0.benefits', ['300 Mo', 'Support']);

        $package->syncTranslations(['en' => ['name' => 'Starter storage', 'benefits' => ['300 MB', '', 'Support']]]);

        $this->withHeader('Accept-Language', 'en')->getJson('/api/v1/packages')
            ->assertJsonPath('packages.0.name', 'Starter storage')
            ->assertJsonPath('packages.0.description', 'Pour démarrer')
            ->assertJsonPath('packages.0.benefits', ['300 MB', 'Support']);

        $this->assertSame('Stockage Starter', $package->fresh()->getAttributes()['name']);
    }

    public function test_legal_page_is_served_in_english_on_the_api_and_with_lang_on_the_web(): void
    {
        $page = LegalPage::create(['slug' => 'cgu', 'title' => 'Conditions', 'content' => '<p>Règles</p>', 'is_active' => true, 'order' => 1]);
        $page->syncTranslations(['en' => ['title' => 'Terms', 'content' => '<p>Rules</p>']]);

        $this->withHeader('Accept-Language', 'en')->getJson('/api/v1/legal-pages/cgu')
            ->assertJsonPath('data.title', 'Terms')
            ->assertJsonPath('data.url', route('legal.show', ['slug' => 'cgu', 'lang' => 'en']))
            ->assertJsonPath('data.content', '<p>Rules</p>');

        $this->get('/legal/cgu?lang=en')->assertOk()->assertSee('<p>Rules</p>', false);
        $this->get('/legal/cgu')->assertOk()->assertSee('<p>Règles</p>', false);
    }

    public function test_public_settings_are_cached_per_language(): void
    {
        Setting::set('app_slogan', 'Votre plateforme', 'string', 'general');
        Setting::where('key', 'app_slogan')->first()->setTranslation('value', 'en', 'Your platform');

        $this->withHeader('Accept-Language', 'fr')->getJson('/api/settings/app_slogan')->assertJsonFragment(['value' => 'Votre plateforme']);
        $this->withHeader('Accept-Language', 'en')->getJson('/api/settings/app_slogan')->assertJsonFragment(['value' => 'Your platform']);
        $this->withHeader('Accept-Language', 'fr')->getJson('/api/settings/app_slogan')->assertJsonFragment(['value' => 'Votre plateforme']);
    }

    public function test_vendor_edits_french_text_and_english_version_separately(): void
    {
        $vendor = User::factory()->create(['role' => 'vendeur']);
        $shop = Shop::create(['user_id' => $vendor->id, 'name' => 'Ma boutique', 'slug' => 'ma-boutique', 'status' => 'active']);
        $product = Product::create([
            'user_id' => $vendor->id, 'shop_id' => $shop->id, 'category_id' => $this->category()->id,
            'name' => 'Chaise en bois', 'description' => 'Belle chaise', 'price' => 10000, 'stock' => 5, 'status' => 'active',
        ]);
        Sanctum::actingAs($vendor);

        $this->withHeader('Accept-Language', 'en')
            ->putJson("/api/v1/vendor/products/{$product->id}", [
                'name' => 'Chaise en chêne',
                'translations' => ['en' => ['name' => 'Oak chair', 'description' => 'Nice chair']],
            ])
            ->assertOk()
            ->assertJsonPath('product.name', 'Chaise en chêne')
            ->assertJsonPath('product.translations.en.name', 'Oak chair')
            ->assertJsonPath('product.category.name', 'Electronics');

        // Le vendeur anglophone retrouve son texte français dans sa liste.
        $this->withHeader('Accept-Language', 'en')->getJson('/api/v1/vendor/products')
            ->assertJsonPath('data.0.name', 'Chaise en chêne')
            ->assertJsonPath('data.0.translations.en.description', 'Nice chair');

        // L'acheteur voit la version de sa langue.
        $this->withHeader('Accept-Language', 'en')->getJson("/api/v1/products/{$product->id}")
            ->assertJsonPath('product.name', 'Oak chair');
        $this->withHeader('Accept-Language', 'fr')->getJson("/api/v1/products/{$product->id}")
            ->assertJsonPath('product.name', 'Chaise en chêne');

        $this->assertSame('chaise-en-chene', $product->fresh()->slug);

        // Un champ vidé supprime la traduction.
        $this->putJson("/api/v1/vendor/products/{$product->id}", ['translations' => ['en' => ['name' => '']]])->assertOk();
        $this->assertNull($product->fresh()->getTranslation('name', 'en'));
    }

    public function test_shop_categories_are_stored_in_french_and_shown_in_the_request_language(): void
    {
        $this->category();
        $vendor = User::factory()->create(['role' => 'vendeur']);
        Shop::create(['user_id' => $vendor->id, 'name' => 'Ma boutique', 'slug' => 'ma-boutique', 'status' => 'active']);
        Sanctum::actingAs($vendor);

        $this->withHeader('Accept-Language', 'en')
            ->putJson('/api/v1/vendor/shop', [
                'categories' => ['Electronics'],
                'translations' => ['en' => ['description' => 'My shop']],
            ])
            ->assertOk()
            ->assertJsonPath('shop.categories', ['Electronics'])
            ->assertJsonPath('shop.translations.en.description', 'My shop');

        $this->assertSame(['Électronique'], $vendor->primaryShop->fresh()->categories);

        $this->withHeader('Accept-Language', 'fr')->getJson('/api/v1/vendor/shop')
            ->assertJsonPath('shop.categories', ['Électronique']);
    }

    public function test_translations_are_removed_with_their_record(): void
    {
        $package = $this->package();
        $package->setTranslation('name', 'en', 'Starter');
        $package->delete();

        $this->assertDatabaseCount('translations', 0);
    }
}
