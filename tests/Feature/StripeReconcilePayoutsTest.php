<?php

namespace Tests\Feature;

use App\Models\PlatformWithdrawal;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\FirebaseMessagingService;
use App\Services\StripeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Stripe\Payout;
use Tests\TestCase;

/**
 * Filet de sécurité `stripe:reconcile-payouts`.
 *
 * Tant qu'aucun endpoint webhook public n'est déclaré (et si un événement se perd),
 * un virement pourtant arrivé resterait affiché « en cours » au vendeur.
 */
class StripeReconcilePayoutsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(FirebaseMessagingService::class, function ($mock) {
            $mock->shouldReceive('sendToUser')->andReturn([]);
        });
    }

    private function pendingWithdrawal(User $user): PlatformWithdrawal
    {
        $withdrawal = PlatformWithdrawal::create([
            'user_id' => $user->id,
            'amount_requested' => 10000,
            'commission_rate' => 0,
            'commission_amount' => 0,
            'amount_sent' => 10000,
            'currency' => 'XAF',
            'payout_amount' => 15.24,
            'payout_currency' => 'EUR',
            'exchange_rate' => 0.001524,
            'provider' => 'stripe',
            'payment_method' => 'stripe_connect',
            'payment_account' => 'IBAN ****2606',
            'payment_account_name' => 'Vendeur Test',
            'status' => 'processing',
            'transaction_reference' => 'TXN-' . uniqid(),
            'stripe_transfer_id' => 'tr_x',
            'stripe_payout_id' => 'po_x',
        ]);

        WalletTransaction::create([
            'user_id' => $user->id,
            'type' => 'debit',
            'amount' => 10000,
            'balance_before' => 100000,
            'balance_after' => 90000,
            'description' => 'Virement IBAN ****2606',
            'status' => 'pending',
            'provider' => 'stripe',
            'reference_type' => 'platform_withdrawal',
            'reference_id' => $withdrawal->id,
        ]);

        return $withdrawal;
    }

    private function seller(): User
    {
        $user = User::factory()->create();
        $user->forceFill([
            'stripe_account_id' => 'acct_seller',
            'stripe_account_status' => 'approved',
            'stripe_bank_country' => 'FR',
        ])->saveQuietly();
        $user->creditKpay('XAF', 90000);

        return $user;
    }

    public function test_paid_payout_closes_the_withdrawal(): void
    {
        $user = $this->seller();
        $withdrawal = $this->pendingWithdrawal($user);

        $this->mock(StripeService::class, function ($mock) {
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('retrievePayout')->once()->andReturn(
                Payout::constructFrom(['id' => 'po_x', 'status' => 'paid', 'metadata' => ['transfer_id' => 'tr_x']])
            );
        });

        $this->artisan('stripe:reconcile-payouts')->assertSuccessful();

        $this->assertSame('completed', $withdrawal->fresh()->status);
        $this->assertSame('completed', WalletTransaction::where('reference_id', $withdrawal->id)->first()->status);
    }

    /** Un échec constaté a posteriori doit recréditer le vendeur dans SA devise. */
    public function test_failed_payout_refunds_wallet_currency(): void
    {
        $user = $this->seller();
        $withdrawal = $this->pendingWithdrawal($user);

        $this->mock(StripeService::class, function ($mock) {
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('retrievePayout')->once()->andReturn(
                Payout::constructFrom([
                    'id' => 'po_x',
                    'status' => 'failed',
                    'failure_code' => 'account_closed',
                    'failure_message' => 'Compte bancaire fermé.',
                    'metadata' => ['transfer_id' => 'tr_x'],
                ])
            );
        });

        $this->artisan('stripe:reconcile-payouts')->assertSuccessful();

        $this->assertSame('failed', $withdrawal->fresh()->status);
        // 90 000 restants + 10 000 remboursés, en XAF et non en EUR.
        $this->assertEqualsWithDelta(100000.0, $user->fresh()->kpayBalanceFor('XAF'), 0.001);
    }

    public function test_in_transit_payout_is_left_untouched(): void
    {
        $user = $this->seller();
        $withdrawal = $this->pendingWithdrawal($user);

        $this->mock(StripeService::class, function ($mock) {
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('retrievePayout')->once()->andReturn(
                Payout::constructFrom(['id' => 'po_x', 'status' => 'in_transit', 'metadata' => []])
            );
        });

        $this->artisan('stripe:reconcile-payouts')->assertSuccessful();

        $this->assertSame('processing', $withdrawal->fresh()->status);
        $this->assertEqualsWithDelta(90000.0, $user->fresh()->kpayBalanceFor('XAF'), 0.001);
    }
}
