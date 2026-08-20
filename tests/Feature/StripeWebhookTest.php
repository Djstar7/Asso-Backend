<?php

namespace Tests\Feature;

use App\Models\PlatformWithdrawal;
use App\Models\User;
use App\Models\WalletBalance;
use App\Models\WalletTransaction;
use App\Services\FirebaseMessagingService;
use App\Services\StripeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Stripe\Event;
use Tests\TestCase;

/**
 * Webhook Stripe : finalisation des virements IBAN.
 *
 * StripeService::constructWebhookEvent est MOCKÉ (pas besoin de clés/signature
 * réelles) — on vérifie la logique du contrôleur : transition de statut,
 * idempotence, et remboursement du wallet en cas d'échec.
 */
class StripeWebhookTest extends TestCase
{
    use RefreshDatabase;

    /** Mocke StripeService pour renvoyer l'événement fourni, et neutralise FCM. */
    private function mockEvent(array $eventData): void
    {
        $event = Event::constructFrom($eventData);

        $this->mock(StripeService::class, function ($mock) use ($event) {
            $mock->shouldReceive('constructWebhookEvent')->andReturn($event);
        });

        $this->mock(FirebaseMessagingService::class, function ($mock) {
            $mock->shouldReceive('sendToUser')->andReturn([]);
        });
    }

    private function makeWithdrawal(User $user, string $status = 'processing'): PlatformWithdrawal
    {
        return PlatformWithdrawal::create([
            'user_id' => $user->id,
            'amount_requested' => 20,
            'commission_rate' => 0,
            'commission_amount' => 0,
            'amount_sent' => 20,
            'currency' => 'EUR',
            'provider' => 'stripe',
            'payment_method' => 'stripe_connect',
            'payment_account' => 'IBAN ****2606',
            'payment_account_name' => 'Jean Dupont',
            'status' => $status,
            'transaction_reference' => 'TXN-' . uniqid(),
            'stripe_transfer_id' => 'tr_test',
            'stripe_payout_id' => 'po_test',
        ]);
    }

    public function test_payout_paid_marks_withdrawal_completed(): void
    {
        $user = User::factory()->create();
        $withdrawal = $this->makeWithdrawal($user);

        WalletTransaction::create([
            'user_id' => $user->id,
            'type' => 'debit',
            'amount' => 20,
            'balance_before' => 50,
            'balance_after' => 30,
            'description' => 'Virement IBAN',
            'status' => 'pending',
            'provider' => 'stripe',
            'reference_type' => 'platform_withdrawal',
            'reference_id' => $withdrawal->id,
        ]);

        $this->mockEvent([
            'type' => 'payout.paid',
            'account' => 'acct_1',
            'data' => ['object' => [
                'id' => 'po_test',
                'object' => 'payout',
                'amount' => 2000,
                'currency' => 'eur',
                'metadata' => ['transfer_id' => 'tr_test'],
            ]],
        ]);

        $this->postJson('/api/v1/stripe/webhook', [])->assertOk();

        $this->assertSame('completed', $withdrawal->fresh()->status);
        $this->assertSame('completed', WalletTransaction::where('reference_id', $withdrawal->id)
            ->where('type', 'debit')->first()->status);
    }

    public function test_payout_failed_marks_failed_and_refunds_wallet(): void
    {
        $user = User::factory()->create();
        WalletBalance::create(['user_id' => $user->id, 'currency' => 'EUR', 'balance' => 30, 'locked_balance' => 0]);
        $withdrawal = $this->makeWithdrawal($user);

        WalletTransaction::create([
            'user_id' => $user->id,
            'type' => 'debit',
            'amount' => 20,
            'balance_before' => 50,
            'balance_after' => 30,
            'description' => 'Virement IBAN',
            'status' => 'pending',
            'provider' => 'stripe',
            'reference_type' => 'platform_withdrawal',
            'reference_id' => $withdrawal->id,
        ]);

        $this->mockEvent([
            'type' => 'payout.failed',
            'account' => 'acct_1',
            'data' => ['object' => [
                'id' => 'po_test',
                'object' => 'payout',
                'amount' => 2000,
                'currency' => 'eur',
                'failure_code' => 'account_closed',
                'failure_message' => 'The bank account has been closed.',
                'metadata' => ['transfer_id' => 'tr_test'],
            ]],
        ]);

        $this->postJson('/api/v1/stripe/webhook', [])->assertOk();

        $this->assertSame('failed', $withdrawal->fresh()->status);
        // Solde recrédité : 30 + 20 = 50
        $this->assertEquals(50.0, (float) $user->fresh()->kpayBalanceFor('EUR'));
        // Transaction de remboursement créée
        $this->assertDatabaseHas('wallet_transactions', [
            'reference_id' => $withdrawal->id,
            'type' => 'refund',
            'provider' => 'stripe',
        ]);
    }

    public function test_payout_paid_is_idempotent(): void
    {
        $user = User::factory()->create();
        WalletBalance::create(['user_id' => $user->id, 'currency' => 'EUR', 'balance' => 30, 'locked_balance' => 0]);
        $withdrawal = $this->makeWithdrawal($user, 'completed');

        $this->mockEvent([
            'type' => 'payout.failed',
            'account' => 'acct_1',
            'data' => ['object' => [
                'id' => 'po_test',
                'object' => 'payout',
                'amount' => 2000,
                'currency' => 'eur',
                'metadata' => ['transfer_id' => 'tr_test'],
            ]],
        ]);

        $this->postJson('/api/v1/stripe/webhook', [])->assertOk();

        // Un retrait déjà "completed" ne doit PAS être remboursé ni repassé en failed.
        $this->assertSame('completed', $withdrawal->fresh()->status);
        $this->assertEquals(30.0, (float) $user->fresh()->kpayBalanceFor('EUR'));
    }
}
