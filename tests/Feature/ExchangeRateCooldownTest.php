<?php

namespace Tests\Feature;

use App\Models\ServiceConfiguration;
use App\Services\ExchangeRateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Quota exchangerate-api épuisé : chaque conversion relançait un appel HTTP
 * (800+ par jour), ce qui entretenait le « quota-reached » et faisait échouer
 * les paiements carte. L'API est désormais mise en pause après un échec, et la
 * parité fixe du franc CFA sert de dernier recours.
 */
class ExchangeRateCooldownTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        ServiceConfiguration::create([
            'service_name' => 'exchange_rate',
            'service_type' => 'exchange',
            'is_active' => true,
            'configuration' => ['api_key' => 'test_key'],
        ]);
    }

    public function test_quota_reached_pauses_api_calls(): void
    {
        Http::fake([
            'v6.exchangerate-api.com/*' => Http::response(['result' => 'error', 'error-type' => 'quota-reached'], 200),
        ]);

        ExchangeRateService::rate('USD', 'JPY');
        ExchangeRateService::rate('USD', 'JPY');
        ExchangeRateService::rate('GBP', 'JPY');

        // Un seul appel : les suivants sont court-circuités par la pause.
        Http::assertSentCount(1);
        $this->assertTrue(ExchangeRateService::apiInCooldown());
    }

    public function test_cfa_fixed_parity_is_last_resort(): void
    {
        Http::fake([
            'v6.exchangerate-api.com/*' => Http::response(['result' => 'error', 'error-type' => 'quota-reached'], 200),
        ]);

        $this->assertEqualsWithDelta(1 / 655.957, ExchangeRateService::rate('XAF', 'EUR'), 0.0000001);
        $this->assertEqualsWithDelta(655.957, ExchangeRateService::rate('EUR', 'XOF'), 0.001);
        // Hors zone CFA : toujours null, jamais deviné.
        $this->assertNull(ExchangeRateService::rate('XAF', 'USD'));
    }

    public function test_successful_rate_is_cached(): void
    {
        Http::fake([
            'v6.exchangerate-api.com/*' => Http::response(['result' => 'success', 'conversion_rate' => 0.92], 200),
        ]);

        $this->assertSame(0.92, ExchangeRateService::rate('USD', 'EUR'));
        $this->assertSame(0.92, ExchangeRateService::rate('USD', 'EUR'));

        Http::assertSentCount(1);
    }
}
