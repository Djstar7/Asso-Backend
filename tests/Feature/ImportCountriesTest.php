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
}
