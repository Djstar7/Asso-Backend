<?php

namespace Tests\Feature;

use App\Models\ImportCountry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Vérifie l'endpoint public des pays d'origine (produits importés),
 * externalisé en base pour être géré sans redéployer l'app.
 */
class ImportCountriesTest extends TestCase
{
    use RefreshDatabase;

    public function test_endpoint_returns_active_countries_ordered(): void
    {
        ImportCountry::create(['code' => 'AE', 'name' => 'Dubaï', 'flag' => '🇦🇪', 'sort_order' => 3, 'is_active' => true]);
        ImportCountry::create(['code' => 'CN', 'name' => 'Chine', 'flag' => '🇨🇳', 'sort_order' => 1, 'is_active' => true]);
        ImportCountry::create(['code' => 'TR', 'name' => 'Turquie', 'flag' => '🇹🇷', 'sort_order' => 2, 'is_active' => true]);
        // Pays désactivé : ne doit pas apparaître
        ImportCountry::create(['code' => 'IN', 'name' => 'Inde', 'flag' => '🇮🇳', 'sort_order' => 4, 'is_active' => false]);

        $res = $this->getJson('/api/v1/import-countries');
        $res->assertOk();
        $res->assertJsonPath('success', true);

        $codes = collect($res->json('countries'))->pluck('code')->all();
        $this->assertSame(['CN', 'TR', 'AE'], $codes, 'Actifs, triés par sort_order, sans le pays désactivé');

        // Structure d'un élément
        $res->assertJsonPath('countries.0.name', 'Chine');
        $res->assertJsonPath('countries.0.flag', '🇨🇳');
    }

    public function test_seeder_populates_default_countries(): void
    {
        $this->seed(\Database\Seeders\ImportCountrySeeder::class);

        $this->assertSame(3, ImportCountry::count());
        $this->assertNotNull(ImportCountry::where('code', 'TR')->first());
    }

    public function test_search_filters_import_catalog_and_counts_results_per_country(): void
    {
        foreach ([['CN', 'Chine'], ['TR', 'Turquie'], ['AE', 'Dubaï']] as $i => [$code, $name]) {
            ImportCountry::create(['code' => $code, 'name' => $name, 'flag' => '', 'sort_order' => $i, 'is_active' => true]);
        }
        $seller = \App\Models\User::factory()->create();
        $shop = \App\Models\Shop::create(['user_id' => $seller->id, 'name' => 'Import', 'slug' => 'imp-' . uniqid(), 'status' => 'active']);
        $category = \App\Models\Category::create(['name' => 'Tech', 'slug' => 'tech-' . uniqid()]);
        foreach ([['CN', 'Chargeur rapide USB-C'], ['CN', 'Écouteurs Bluetooth'], ['TR', 'Chargeur sans fil'], ['AE', 'Parfum oud']] as [$country, $name]) {
            \App\Models\Product::create([
                'user_id' => $seller->id, 'shop_id' => $shop->id, 'category_id' => $category->id,
                'name' => $name, 'slug' => \Illuminate\Support\Str::slug($name) . uniqid(), 'price' => 1000, 'currency' => 'XAF',
                'stock' => 10, 'status' => 'active', 'is_wholesale' => true, 'origin_country' => $country,
            ]);
        }

        $names = collect($this->getJson('/api/v1/import/CN/products?q=CHARGEUR')->assertOk()->json('products'))->pluck('name')->all();
        $this->assertSame(['Chargeur rapide USB-C'], $names);
        $this->assertCount(2, $this->getJson('/api/v1/import/CN/products')->json('products'));

        $this->getJson('/api/v1/import/search?q=chargeur')->assertOk()
            ->assertJsonPath('counts.CN', 1)
            ->assertJsonPath('counts.TR', 1)
            ->assertJsonMissingPath('counts.AE');
        $this->getJson('/api/v1/import/search')->assertStatus(422);
    }

    public function test_catalog_is_paginated_from_the_most_recent_product(): void
    {
        ImportCountry::create(['code' => 'CN', 'name' => 'Chine', 'flag' => '', 'sort_order' => 1, 'is_active' => true]);
        $seller = \App\Models\User::factory()->create();
        $shop = \App\Models\Shop::create(['user_id' => $seller->id, 'name' => 'Import', 'slug' => 'imp-' . uniqid(), 'status' => 'active']);
        $category = \App\Models\Category::create(['name' => 'Tech', 'slug' => 'tech-' . uniqid()]);
        // Même seconde de création : l'ordre reste stable d'une page à l'autre.
        foreach (range(1, 5) as $i) {
            \App\Models\Product::create([
                'user_id' => $seller->id, 'shop_id' => $shop->id, 'category_id' => $category->id,
                'name' => "Article {$i}", 'slug' => "article-{$i}-" . uniqid(), 'price' => 1000, 'currency' => 'XAF',
                'stock' => 10, 'status' => 'active', 'is_wholesale' => true, 'origin_country' => 'CN',
            ]);
        }

        $first = $this->getJson('/api/v1/import/CN/products?page=1&per_page=2')->assertOk()
            ->assertJsonPath('pagination.total', 5)
            ->assertJsonPath('pagination.last_page', 3)
            ->assertJsonPath('pagination.has_more', true);
        $this->assertSame(['Article 5', 'Article 4'], collect($first->json('products'))->pluck('name')->all());

        $last = $this->getJson('/api/v1/import/CN/products?page=3&per_page=2')->assertJsonPath('pagination.has_more', false);
        $this->assertSame(['Article 1'], collect($last->json('products'))->pluck('name')->all());

        // Taille de page plafonnée ; sans `page`, anciennes versions : tout le catalogue.
        $this->getJson('/api/v1/import/CN/products?page=1&per_page=500')->assertJsonPath('pagination.per_page', 50);
        $this->getJson('/api/v1/import/CN/products')->assertJsonCount(5, 'products')->assertJsonPath('pagination', null);
    }
}
