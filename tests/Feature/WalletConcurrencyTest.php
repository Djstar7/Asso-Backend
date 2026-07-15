<?php

namespace Tests\Feature;

use App\Jobs\Wallet\CleanupStaleTransactionsJob;
use App\Jobs\Wallet\ProcessDepositStatusJob;
use App\Models\PlatformWithdrawal;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\FirebaseMessagingService;
use App\Services\KPayService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

/**
 * Vérifie les protections anti-course et la réconciliation des retraits :
 *  - un dépôt traité 2 fois ne crédite qu'une seule fois (idempotence + verrou)
 *  - le débit de retrait empêche un second retrait au-delà du solde restant
 *  - le cleanup ne rembourse JAMAIS sans vérification finale KPay (anti double-paiement)
 */
class WalletConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function mockFcm(): void
    {
        $fcm = Mockery::mock(FirebaseMessagingService::class);
        $fcm->shouldReceive('sendToUser')->andReturn(['success' => true, 'success_count' => 1]);
        $this->app->instance(FirebaseMessagingService::class, $fcm);
    }

    private function makeDebitedWithdrawal(User $user, float $amount, string $currency = 'XAF'): PlatformWithdrawal
    {
        // Simule l'état après une demande de retrait : le solde a déjà été débité.
        $user->debitKpay($currency, $amount);

        WalletTransaction::create([
            'user_id' => $user->id,
            'type' => 'debit',
            'amount' => $amount,
            'balance_before' => 0,
            'balance_after' => 0,
            'description' => 'Retrait test',
            'status' => 'pending',
            'provider' => 'kpay',
            'reference_type' => 'platform_withdrawal',
            'reference_id' => null,
        ]);

        $w = PlatformWithdrawal::create([
            'user_id' => $user->id,
            'amount_requested' => $amount,
            'commission_rate' => 0,
            'commission_amount' => 0,
            'amount_sent' => $amount,
            'currency' => $currency,
            'provider' => 'kpay',
            'payment_method' => 'MTN_MOMO_CMR',
            'payment_account' => '699000000',
            'status' => 'processing',
            'transaction_reference' => 'WTH-TEST-' . uniqid(),
            'kpay_reference' => 'wdr_test_' . uniqid(),
        ]);

        WalletTransaction::where('user_id', $user->id)
            ->where('reference_type', 'platform_withdrawal')
            ->whereNull('reference_id')
            ->update(['reference_id' => $w->id]);

        // Antidater à plus de 7 jours pour tomber dans la fenêtre du cleanup
        PlatformWithdrawal::whereKey($w->id)->update(['created_at' => now()->subDays(8)]);

        return $w->fresh();
    }

    public function test_deposit_processed_twice_credits_only_once(): void
    {
        $this->mockFcm();

        $kpay = Mockery::mock(KPayService::class);
        $kpay->shouldReceive('checkPaymentStatus')->andReturn(['status' => 'SUCCESS']);
        $this->app->instance(KPayService::class, $kpay);

        $user = User::factory()->create();
        $deposit = WalletTransaction::create([
            'user_id' => $user->id,
            'type' => 'credit',
            'amount' => 5000,
            'balance_before' => 0,
            'balance_after' => 0,
            'description' => 'Dépôt test',
            'status' => 'pending',
            'provider' => 'kpay',
            'metadata' => ['provider_reference' => 'pay_test_123', 'currency' => 'XAF'],
        ]);

        // Deux exécutions successives (webhook + polling) ne doivent créditer qu'une fois
        (new ProcessDepositStatusJob($deposit->id))->handle(app(KPayService::class), app(FirebaseMessagingService::class));
        (new ProcessDepositStatusJob($deposit->id))->handle(app(KPayService::class), app(FirebaseMessagingService::class));

        $this->assertSame('completed', $deposit->fresh()->status);
        $this->assertSame(5000.0, $user->fresh()->kpayAvailableFor('XAF'));
    }

    public function test_withdrawal_debits_then_second_over_remaining_balance_is_rejected(): void
    {
        $this->mockFcm();

        $kpay = Mockery::mock(KPayService::class);
        $kpay->shouldReceive('initiateDisbursement')->andReturn([
            'success' => true, 'id' => 'wdr_1', 'reference' => 'ref_1', 'data' => [],
        ]);
        $this->app->instance(KPayService::class, $kpay);

        $user = User::factory()->create();
        $user->creditKpay('XAF', 10000);

        Sanctum::actingAs($user);

        // 1er retrait de 6000 → OK, solde restant 4000
        $res1 = $this->postJson('/api/v1/wallet/withdraw/kpay', [
            'amount' => 6000, 'provider' => 'MTN_MOMO_CMR', 'phone' => '699000000',
        ]);
        $res1->assertOk();
        $this->assertSame(4000.0, $user->fresh()->kpayAvailableFor('XAF'));

        // 2e retrait de 6000 → refusé (solde restant 4000), aucun débit supplémentaire
        $res2 = $this->postJson('/api/v1/wallet/withdraw/kpay', [
            'amount' => 6000, 'provider' => 'MTN_MOMO_CMR', 'phone' => '699000000',
        ]);
        $res2->assertStatus(400);
        $this->assertSame(4000.0, $user->fresh()->kpayAvailableFor('XAF'));
        $this->assertSame(1, PlatformWithdrawal::where('user_id', $user->id)->count());
    }

    public function test_cleanup_completes_stale_withdrawal_when_kpay_reports_success_without_refund(): void
    {
        $this->mockFcm();

        $kpay = Mockery::mock(KPayService::class);
        $kpay->shouldReceive('checkDisbursementStatus')->andReturn(['status' => 'SUCCESS']);
        $this->app->instance(KPayService::class, $kpay);

        $user = User::factory()->create();
        $user->creditKpay('XAF', 10000);
        $w = $this->makeDebitedWithdrawal($user, 6000); // solde débité → 4000

        (new CleanupStaleTransactionsJob())->handle();

        // KPay a réellement envoyé l'argent → complété, PAS de remboursement
        $this->assertSame('completed', $w->fresh()->status);
        $this->assertSame(0, WalletTransaction::where('user_id', $user->id)->where('type', 'refund')->count());
        $this->assertSame(4000.0, $user->fresh()->kpayAvailableFor('XAF'));
    }

    public function test_cleanup_refunds_stale_withdrawal_when_kpay_reports_failure(): void
    {
        $this->mockFcm();

        $kpay = Mockery::mock(KPayService::class);
        $kpay->shouldReceive('checkDisbursementStatus')->andReturn(['status' => 'FAILED', 'reason' => 'Rejeté']);
        $this->app->instance(KPayService::class, $kpay);

        $user = User::factory()->create();
        $user->creditKpay('XAF', 10000);
        $w = $this->makeDebitedWithdrawal($user, 6000); // solde débité → 4000

        (new CleanupStaleTransactionsJob())->handle();

        // KPay a échoué → remboursé, solde restauré
        $this->assertSame('failed', $w->fresh()->status);
        $this->assertSame(1, WalletTransaction::where('user_id', $user->id)->where('type', 'refund')->count());
        $this->assertSame(10000.0, $user->fresh()->kpayAvailableFor('XAF'));
    }

    public function test_cleanup_times_out_and_refunds_when_kpay_still_pending_after_7_days(): void
    {
        $this->mockFcm();

        $kpay = Mockery::mock(KPayService::class);
        $kpay->shouldReceive('checkDisbursementStatus')->andReturn(['status' => 'PENDING']);
        $this->app->instance(KPayService::class, $kpay);

        $user = User::factory()->create();
        $user->creditKpay('XAF', 10000);
        $w = $this->makeDebitedWithdrawal($user, 6000);

        (new CleanupStaleTransactionsJob())->handle();

        // Toujours pending après 7 jours → timeout + remboursement (dernier recours)
        $this->assertSame('failed', $w->fresh()->status);
        $this->assertSame('TIMEOUT', $w->fresh()->failure_code);
        $this->assertSame(1, WalletTransaction::where('user_id', $user->id)->where('type', 'refund')->count());
        $this->assertSame(10000.0, $user->fresh()->kpayAvailableFor('XAF'));
    }
}
