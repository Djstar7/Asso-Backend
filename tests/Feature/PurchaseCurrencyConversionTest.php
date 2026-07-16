<?php

namespace Tests\Feature;

use App\Models\ServiceConfiguration;
use App\Services\KPayCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Vérifie la conversion du montant selon l'opérateur Mobile Money choisi,
 * appliquée avant le lancement d'un paiement (achat produit kpay_direct).
 */
class PurchaseCurrencyConversionTest extends TestCase
{
    use RefreshDatabase;

    public function test_operator_currency_is_derived_from_provider(): void
    {
        // Opérateur camerounais → XAF (devise de base, pas de conversion)
        $this->assertSame('XAF', KPayCatalog::currencyForProvider('MTN_MOMO_CMR'));
        // Opérateur béninois → XOF
        $this->assertSame('XOF', KPayCatalog::currencyForProvider('MTN_MOMO_BEN'));
        // Opérateur inconnu / nul → XAF par défaut
        $this->assertSame('XAF', KPayCatalog::currencyForProvider('UNKNOWN'));
        $this->assertSame('XAF', KPayCatalog::currencyForProvider(null));
    }

    public function test_convert_same_currency_returns_amount_unchanged(): void
    {
        $res = $this->getJson('/api/v1/currencies/convert?from=XAF&to=XAF&amount=10000');

        $res->assertOk();
        // Même devise → montant inchangé (pas de conversion pour un opérateur XAF)
        $res->assertJsonPath('data.converted', 10000);
        $this->assertEquals(1.0, $res->json('data.rate'));
    }

    public function test_convert_to_other_currency_applies_rate(): void
    {
        // Clé API de taux configurée + réponse externe simulée
        ServiceConfiguration::create([
            'service_name' => 'exchange_rate',
            'service_type' => 'exchange',
            'is_active' => true,
            'configuration' => ['api_key' => 'test_key'],
        ]);
        Http::fake([
            'v6.exchangerate-api.com/*' => Http::response(['result' => 'success', 'conversion_rate' => 0.27], 200),
        ]);

        $res = $this->getJson('/api/v1/currencies/convert?from=XAF&to=NGN&amount=10000');

        $res->assertOk();
        $res->assertJsonPath('data.rate', 0.27);
        // 10000 * 0.27 = 2700
        $res->assertJsonPath('data.converted', 2700);
    }
}
