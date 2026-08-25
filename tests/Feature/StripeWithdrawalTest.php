<?php

namespace Tests\Feature;

use App\Exceptions\StripePayoutUnavailableException;
use App\Models\PlatformWithdrawal;
use App\Models\User;
use App\Services\FirebaseMessagingService;
use App\Services\StripeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
