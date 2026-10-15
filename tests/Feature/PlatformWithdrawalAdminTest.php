<?php

namespace Tests\Feature;

use App\Models\PlatformWithdrawal;
use App\Models\ServiceConfiguration;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Retrait ASSO depuis le back-office : le retrait Wallet existant, exécuté au
 * nom du compte plateforme (soldes mobile / bancaire séparés).
 */
class PlatformWithdrawalAdminTest extends TestCase
{
    use RefreshDatabase;

    private User $platform;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        ServiceConfiguration::setConfig('elgiopay', ['mode' => 'live', 'public_key' => 'pk_live_x', 'secret_key' => 'sk_live_x'], true);
        Http::fake([
            'api.elgiopay.com/api/v1/payouts' => Http::response(['success' => true, 'data' => ['payout_id' => 'PO1', 'status' => 'processing']]),
            '*' => Http::response([], 500),
        ]);

        $this->platform = User::factory()->create(['email' => 'plateforme@asso.test']);
        Setting::set('platform_account_email', 'plateforme@asso.test');
        $this->platform->creditKpay('XAF', 50000);

        $this->admin = User::factory()->create(['first_name' => 'Awa', 'last_name' => 'Admin']);
        $this->admin->forceFill(['role' => 'admin'])->saveQuietly();
    }

    public function test_admin_sees_mobile_and_bank_balances(): void
    {
        $this->actingAs($this->admin)->get('/admin/revenue/withdrawal')
            ->assertOk()
            ->assertSee('Solde mobile')
            ->assertSee('Solde bancaire')
            ->assertSee('50 000 XAF')
            ->assertSee('MTN Mobile Money (Cameroun)');
    }

    public function test_mobile_withdrawal_debits_platform_wallet_only(): void
    {
        $this->admin->creditKpay('XAF', 9000);

        $this->actingAs($this->admin)->post('/admin/revenue/withdrawal/mobile', [
            'provider' => 'ORANGE_CMR', 'phone' => '690000000', 'amount' => 20000, 'notes' => 'Loyer',
        ])->assertRedirect()->assertSessionHas('success');

        $w = PlatformWithdrawal::sole();
        $this->assertSame($this->platform->id, $w->user_id);
        $this->assertSame($this->admin->id, $w->admin_id);
        $this->assertSame('elgiopay:PO1', $w->kpay_reference);
        $this->assertStringContainsString('Loyer', $w->admin_notes);
        $this->assertSame(30000.0, $this->platform->fresh()->kpayAvailableFor('XAF'));
        $this->assertSame(9000.0, $this->admin->fresh()->kpayAvailableFor('XAF'), "le Wallet de l'admin n'est pas touché");

        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/payouts')
            && $r['payout_method'] === 'orange_money' && $r['amount'] === 20000);
    }

    public function test_withdrawal_above_mobile_balance_is_refused(): void
    {
        $this->actingAs($this->admin)->post('/admin/revenue/withdrawal/mobile', [
            'provider' => 'MTN_MOMO_CMR', 'phone' => '670000000', 'amount' => 60000,
        ])->assertRedirect()->assertSessionHas('error');

        $this->assertSame(0, PlatformWithdrawal::count());
        $this->assertSame(50000.0, $this->platform->fresh()->kpayAvailableFor('XAF'));
        Http::assertNothingSent();
    }

    public function test_bank_withdrawal_needs_validated_iban(): void
    {
        $this->actingAs($this->admin)->post('/admin/revenue/withdrawal/bank', ['amount' => 10000, 'currency' => 'XAF'])
            ->assertRedirect()->assertSessionHas('error');

        $this->assertSame(50000.0, $this->platform->fresh()->kpayAvailableFor('XAF'));
    }

    public function test_product_manager_cannot_withdraw(): void
    {
        $manager = User::factory()->create(['role' => 'product_manager', 'roles' => ['product_manager']]);

        $this->actingAs($manager)->post('/admin/revenue/withdrawal/mobile', [
            'provider' => 'MTN_MOMO_CMR', 'phone' => '670000000', 'amount' => 1000,
        ])->assertForbidden();

        $this->assertSame(0, PlatformWithdrawal::count());
    }
}
