<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Currency;
use App\Models\ExchangeRate;
use App\Models\Product;
use App\Models\User;
use App\Services\ExchangeRateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * [2b] Prix produit multi-devises (pivot XAF).
 *
 * Le vendeur fixe un prix dans sa devise ; price_xaf (canonique) est calculé à
 * l'écriture via la conversion fiable (API live → secours table exchange_rates).
 * En test, pas de clé API → on valide le secours DB.
 */
class ProductPricingCurrencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Currency::create(['code' => 'EUR', 'name' => 'Euro', 'symbol' => '€', 'countries' => ['FR'], 'is_active' => true]);
        Currency::create(['code' => 'XAF', 'name' => 'Franc CFA', 'symbol' => 'FCFA', 'countries' => ['CM'], 'is_active' => true]);
        ExchangeRate::create([
            'from_currency' => 'EUR', 'to_currency' => 'XAF',
            'rate' => 655.957, 'effective_date' => '2026-08-15', 'is_active' => true,
        ]);
    }

    private function makeProduct(string $currency, float $price): Product
    {
        $seller = User::factory()->create();
        $category = Category::create(['name' => 'Cat', 'slug' => 'cat-' . uniqid()]);
        return Product::create([
            'user_id' => $seller->id,
            'category_id' => $category->id,
            'name' => 'P',
            'slug' => 'p-' . uniqid(),
            'price' => $price,
            'currency' => $currency,
            'status' => 'active',
        ]);
    }

    public function test_db_fallback_resolves_direct_and_inverse_rates(): void
    {
        $this->assertEqualsWithDelta(655.957, ExchangeRateService::rate('EUR', 'XAF'), 0.001);
        // Inverse déduit de la paire directe
        $this->assertEqualsWithDelta(1 / 655.957, ExchangeRateService::rate('XAF', 'EUR'), 0.0000001);
        // Paire inconnue → null (jamais 1.0 entre devises différentes)
        $this->assertNull(ExchangeRateService::rate('EUR', 'JPY'));
    }

    public function test_price_xaf_computed_for_foreign_currency_product(): void
    {
        $product = $this->makeProduct('EUR', 100);
        // 100 EUR × 655.957 = 65595.70
        $this->assertEqualsWithDelta(65595.70, (float) $product->price_xaf, 0.01);
    }

    public function test_price_xaf_equals_price_for_xaf_product(): void
    {
        $product = $this->makeProduct('XAF', 5000);
        $this->assertEquals(5000, (float) $product->price_xaf);
    }

    public function test_price_xaf_recomputed_on_price_change(): void
    {
        $product = $this->makeProduct('EUR', 100);
        $product->update(['price' => 200]);
        $this->assertEqualsWithDelta(131191.40, (float) $product->fresh()->price_xaf, 0.01);
    }

    public function test_formatted_price_uses_source_currency_symbol(): void
    {
        $product = $this->makeProduct('EUR', 100);
        $this->assertStringContainsString('€', $product->formatted_price);
        $this->assertStringNotContainsString('FCFA', $product->formatted_price);

        $xaf = $this->makeProduct('XAF', 5000);
        $this->assertStringContainsString('FCFA', $xaf->formatted_price);
    }
}
