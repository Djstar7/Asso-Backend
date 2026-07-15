<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Vérifie le cœur monétaire des flux dépôt / retrait :
 *  - crédit (dépôt) et débit (retrait) du solde multi-devise (wallet_balances)
 *  - l'endpoint withdrawal-balances reflète le solde crédité
 *  - un retrait supérieur au solde disponible est rejeté (avant tout appel KPay)
 */
class WalletFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_deposit_credit_then_withdrawal_debit_updates_available_balance(): void
    {
        $user = User::factory()->create();

        // Dépôt : crédit de 10 000 XAF
        $user->creditKpay('XAF', 10000);
        $this->assertSame(10000.0, $user->fresh()->kpayAvailableFor('XAF'));

        // Retrait : débit de 4 000 XAF
        $user->debitKpay('XAF', 4000);
        $this->assertSame(6000.0, $user->fresh()->kpayAvailableFor('XAF'));
    }

    public function test_currencies_are_isolated(): void
    {
        $user = User::factory()->create();
        $user->creditKpay('XAF', 5000);
        $user->creditKpay('XOF', 2000);

        $this->assertSame(5000.0, $user->fresh()->kpayAvailableFor('XAF'));
        $this->assertSame(2000.0, $user->fresh()->kpayAvailableFor('XOF'));
        $this->assertSame(0.0, $user->fresh()->kpayAvailableFor('GNF'));
    }

    public function test_withdrawal_balances_endpoint_reflects_credited_amount(): void
    {
        $user = User::factory()->create();
        $user->creditKpay('XAF', 12000);

        Sanctum::actingAs($user);
        $res = $this->getJson('/api/v1/wallet/withdrawal-balances');

        $res->assertOk();
        $res->assertJsonPath('data.kpay_wallet_balance', 12000);
    }

    public function test_kpay_withdrawal_rejected_when_insufficient_balance(): void
    {
        $user = User::factory()->create();
        $user->creditKpay('XAF', 1000); // solde faible

        Sanctum::actingAs($user);
        $res = $this->postJson('/api/v1/wallet/withdraw/kpay', [
            'amount' => 5000, // > solde disponible
            'provider' => 'MTN_MOMO_CMR',
            'phone' => '699000000',
        ]);

        $res->assertStatus(400);
        $res->assertJsonPath('success', false);

        // Aucun débit ni transaction ne doit avoir été créé
        $this->assertSame(1000.0, $user->fresh()->kpayAvailableFor('XAF'));
        $this->assertSame(0, WalletTransaction::where('user_id', $user->id)->count());
    }
}
