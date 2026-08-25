<?php

namespace Tests\Feature;

use App\Exceptions\StripePayoutUnavailableException;
use App\Models\Currency;
use App\Models\ExchangeRate;
use App\Models\PlatformWithdrawal;
use App\Models\User;
use App\Services\FirebaseMessagingService;
use App\Services\StripeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

/**
 * Retrait vendeur par virement IBAN (POST /v1/wallet/withdraw/stripe).
 *
 * Ces tests figent la règle qui évite les retraits fantômes observés en conditions
 * réelles : rien n'est écrit tant que Stripe n'est pas en mesure de payer (compte
 * vendeur activé + solde plateforme DANS la devise du versement).
 */
class StripeWithdrawalTest extends TestCase
{
    use RefreshDatabase;

    private function approvedSeller(float $balance = 100.0): User
    {
        $user = User::factory()->create();
        $user->forceFill([
            'stripe_account_id' => 'acct_seller',
            'stripe_account_status' => 'approved',
            'stripe_bank_country' => 'FR',
            'stripe_external_last4' => '3000',
            'stripe_account_holder_name' => 'Jean Dupont',
            'stripe_verified_at' => now(),
        ])->saveQuietly();

        $user->creditKpay('EUR', $balance);

        return $user;
    }

    private function silenceFcm(): void
    {
        $this->mock(FirebaseMessagingService::class, function ($mock) {
            $mock->shouldReceive('sendToUser')->andReturn([]);
        });
    }

    public function test_withdrawal_succeeds_and_records_payout(): void
    {
        $this->silenceFcm();
        $this->mock(StripeService::class, function ($mock) {
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('assertPayoutPossible')->once();
            $mock->shouldReceive('payoutToVendor')->once()->andReturn([
                'id' => 'po_1',
                'transfer_id' => 'tr_1',
                'payout_id' => 'po_1',
                'amount_minor' => 2000,
                'currency' => 'eur',
            ]);
        });

        $user = $this->approvedSeller();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/wallet/withdraw/stripe', ['amount' => 20])
            ->assertOk()
            ->assertJsonPath('data.status', 'processing');

        $withdrawal = PlatformWithdrawal::where('user_id', $user->id)->firstOrFail();
        $this->assertSame('po_1', $withdrawal->stripe_payout_id);
        $this->assertSame('tr_1', $withdrawal->stripe_transfer_id);
        // Le solde est bien débité une seule fois.
        $this->assertEqualsWithDelta(80.0, $user->fresh()->kpayBalanceFor('EUR'), 0.001);
    }

    /** Devise absente du solde plateforme : refus AVANT toute écriture. */
    public function test_withdrawal_refused_when_platform_lacks_currency(): void
    {
        $this->mock(StripeService::class, function ($mock) {
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('assertPayoutPossible')->once()->andThrow(
                new StripePayoutUnavailableException(
                    'currency_unavailable',
                    'Solde plateforme Stripe insuffisant en EUR.',
                )
            );
            $mock->shouldReceive('payoutToVendor')->never();
        });

        $user = $this->approvedSeller();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/wallet/withdraw/stripe', ['amount' => 20])
            ->assertStatus(503)
            ->assertJsonPath('success', false);

        $this->assertSame(0, PlatformWithdrawal::where('user_id', $user->id)->count());
        $this->assertEqualsWithDelta(100.0, $user->fresh()->kpayBalanceFor('EUR'), 0.001);
    }

    /** Compte vendeur non activé par Stripe : 422 explicite, solde intact. */
    public function test_withdrawal_refused_when_account_not_ready(): void
    {
        $this->mock(StripeService::class, function ($mock) {
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('assertPayoutPossible')->once()->andThrow(
                new StripePayoutUnavailableException(
                    'account_not_ready',
                    "Le compte de virement du vendeur n'est pas encore activé par Stripe.",
                )
            );
            $mock->shouldReceive('payoutToVendor')->never();
        });

        $user = $this->approvedSeller();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/wallet/withdraw/stripe', ['amount' => 20])
            ->assertStatus(422);

        $this->assertEqualsWithDelta(100.0, $user->fresh()->kpayBalanceFor('EUR'), 0.001);
    }

    /** Payout refusé en cours de route : rollback complet, solde intact. */
    public function test_withdrawal_rolls_back_when_payout_refused(): void
    {
        $this->silenceFcm();
        $this->mock(StripeService::class, function ($mock) {
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('assertPayoutPossible')->once();
            $mock->shouldReceive('payoutToVendor')->once()->andThrow(
                new StripePayoutUnavailableException('payout_refused', 'Payout refusé.')
            );
        });

        $user = $this->approvedSeller();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/wallet/withdraw/stripe', ['amount' => 20])
            ->assertStatus(503);

        $this->assertSame(0, PlatformWithdrawal::where('user_id', $user->id)->count());
        $this->assertEqualsWithDelta(100.0, $user->fresh()->kpayBalanceFor('EUR'), 0.001);
    }

    public function test_withdrawal_blocked_when_account_not_approved(): void
    {
        $this->mock(StripeService::class, function ($mock) {
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('assertPayoutPossible')->never();
        });

        $user = User::factory()->create();
        $user->creditKpay('EUR', 100);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/wallet/withdraw/stripe', ['amount' => 20])
            ->assertStatus(422);
    }

    /**
     * Vendeur payé en XAF, IBAN en EUR : c'est le cas courant chez ASSO.
     * Le taux vient de la base (l'API live est neutralisée dans les tests).
     */
    private function xafSeller(float $balance = 100000): User
    {
        Http::fake(); // pas d'appel à l'API de taux : on veut le taux stocké.

        Currency::firstOrCreate(['code' => 'XAF'], ['name' => 'Franc CFA', 'symbol' => 'FCFA', 'is_active' => true]);
        Currency::firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'symbol' => '€', 'is_active' => true]);

        ExchangeRate::create([
            'from_currency' => 'XAF',
            'to_currency' => 'EUR',
            'rate' => 0.0015,
            'effective_date' => now()->toDateString(),
            'is_active' => true,
            'source' => 'test',
        ]);

        $user = User::factory()->create();
        $user->forceFill([
            'stripe_account_id' => 'acct_seller',
            'stripe_account_status' => 'approved',
            'stripe_bank_country' => 'FR',
            'stripe_external_last4' => '3000',
            'stripe_account_holder_name' => 'Jean Testeur',
            'stripe_verified_at' => now(),
        ])->saveQuietly();

        $user->creditKpay('XAF', $balance);

        return $user;
    }

    /** Le devis montre au vendeur ce qu'il recevra réellement sur son compte. */
    public function test_quote_converts_wallet_currency_to_payout_currency(): void
    {
        $this->mock(StripeService::class, function ($mock) {
            $mock->shouldReceive('isConfigured')->andReturn(true);
        });

        $user = $this->xafSeller();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/wallet/withdraw/stripe/quote', ['amount' => 100000])
            ->assertOk()
            ->assertJsonPath('data.currency', 'XAF')
            ->assertJsonPath('data.payout_currency', 'EUR')
            ->assertJsonPath('data.payout_amount', 150)   // 100 000 × 0,0015
            ->assertJsonPath('data.rate', 0.0015)
            ->assertJsonPath('data.can_withdraw', true);
    }

    /** Le débit se fait en XAF, le versement en EUR : les deux sont tracés. */
    public function test_withdrawal_debits_wallet_currency_and_pays_out_converted_amount(): void
    {
        $this->silenceFcm();
        $this->mock(StripeService::class, function ($mock) {
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('assertPayoutPossible')->once()
                ->withArgs(fn ($acct, $amount, $currency) => $currency === 'EUR' && abs($amount - 15.0) < 0.001);
            $mock->shouldReceive('payoutToVendor')->once()
                ->withArgs(fn ($acct, $amount, $currency) => $currency === 'EUR' && abs($amount - 15.0) < 0.001)
                ->andReturn([
                    'id' => 'po_1', 'transfer_id' => 'tr_1', 'payout_id' => 'po_1',
                    'amount_minor' => 1500, 'currency' => 'eur',
                ]);
        });

        $user = $this->xafSeller();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/wallet/withdraw/stripe', ['amount' => 10000, 'currency' => 'XAF'])
            ->assertOk()
            ->assertJsonPath('data.currency', 'XAF')
            ->assertJsonPath('data.payout_currency', 'EUR')
            ->assertJsonPath('data.payout_amount', 15);

        $withdrawal = PlatformWithdrawal::where('user_id', $user->id)->firstOrFail();
        $this->assertSame('XAF', $withdrawal->currency);
        $this->assertSame('EUR', $withdrawal->payout_currency);
        $this->assertEqualsWithDelta(15.0, (float) $withdrawal->payout_amount, 0.001);
        $this->assertEqualsWithDelta(0.0015, (float) $withdrawal->exchange_rate, 0.0000001);

        // Le vendeur est débité en XAF, pas en EUR.
        $this->assertEqualsWithDelta(90000.0, $user->fresh()->kpayBalanceFor('XAF'), 0.001);
    }

    /** Le minimum s'apprécie sur le montant VERSÉ, pas sur le montant débité. */
    public function test_withdrawal_rejects_amount_below_payout_minimum(): void
    {
        $this->mock(StripeService::class, function ($mock) {
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('assertPayoutPossible')->never();
            $mock->shouldReceive('payoutToVendor')->never();
        });

        $user = $this->xafSeller();

        // 1 000 XAF = 1,50 EUR, sous le minimum de 5 EUR.
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/wallet/withdraw/stripe', ['amount' => 1000, 'currency' => 'XAF'])
            ->assertStatus(422);

        $this->assertEqualsWithDelta(100000.0, $user->fresh()->kpayBalanceFor('XAF'), 0.001);
    }

    /** Le solde versable annoncé tient compte de la conversion. */
    public function test_withdrawal_balances_expose_converted_equivalent(): void
    {
        $this->mock(StripeService::class, function ($mock) {
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('platformSupportsCurrency')->andReturn(true);
        });

        $user = $this->xafSeller();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/wallet/withdrawal-balances')
            ->assertOk()
            ->assertJsonPath('data.stripe.currency', 'EUR')
            ->assertJsonPath('data.stripe.available', 150)
            ->assertJsonPath('data.stripe.source.currency', 'XAF')
            ->assertJsonPath('data.stripe.source.available', 100000);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
