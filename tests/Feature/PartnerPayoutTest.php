<?php

namespace Tests\Feature;

use App\Models\Currency;
use App\Models\DelivererCompany;
use App\Models\ExchangeRate;
use App\Models\PartnerLedgerEntry;
use App\Models\User;
use App\Services\PartnerLedgerService;
use App\Services\PartnerPayoutService;
use App\Services\StripeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Compte partenaire : dus des courses, IBAN synchronisé chez Stripe depuis
 * l'admin, virement réel du dû, versement hors application, webhook payout.
 */
class PartnerPayoutTest extends TestCase
{
    use RefreshDatabase;

    private DelivererCompany $partner;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Currency::firstOrCreate(['code' => 'XAF'], ['name' => 'Franc CFA', 'symbol' => 'FCFA', 'is_active' => true]);
        Currency::firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'symbol' => '€', 'is_active' => true]);
        ExchangeRate::create([
            'from_currency' => 'EUR', 'to_currency' => 'XAF', 'rate' => 656,
            'effective_date' => now()->toDateString(), 'is_active' => true, 'source' => 'test',
        ]);

        $this->partner = DelivererCompany::create(['name' => 'Transit Mer', 'service_type' => 'maritime', 'is_active' => true]);
        $this->admin = User::factory()->create(['role' => 'admin']);
        PartnerLedgerService::earn($this->partner->id, 131200, 'maritime_booking', 1, 'Fret maritime — envoi #1');
        PartnerLedgerService::earn($this->partner->id, 65600, 'maritime_booking', 2, 'Fret maritime — envoi #2');
        // Idempotent : la même course ne compte qu'une fois.
        PartnerLedgerService::earn($this->partner->id, 65600, 'maritime_booking', 2, 'Fret maritime — envoi #2');
    }

    private function mockStripe(array $overrides = []): void
    {
        $this->mock(StripeService::class, function ($m) use ($overrides) {
            $m->shouldReceive('isConfigured')->andReturn(true);
            $m->shouldReceive('accountState')->andReturn(['ready' => true, 'missing' => false, 'requirements_due' => []]);
            $m->shouldReceive('createCustomAccountWithBank')->andReturn(['id' => 'acct_partner', 'external_last4' => '6789', 'bank_country' => 'FR']);
            $m->shouldReceive('payoutToVendor')->andReturnUsing($overrides['payout'] ?? fn ($acct, $amount, $currency) => [
                'transfer_id' => 'tr_1', 'payout_id' => 'po_1', 'payout_amount' => $amount,
            ]);
        });
    }

    public function test_due_is_sum_of_earnings(): void
    {
        $this->assertEquals(196800, PartnerLedgerService::balance($this->partner->id)['due']);
    }

    public function test_admin_syncs_iban_then_pays_partner_by_stripe(): void
    {
        $this->mockStripe();
        $this->actingAs($this->admin);

        $this->post(route('admin.delivery-partners.iban', $this->partner), [
            'country' => 'FR', 'iban' => 'FR76 3000 6000 0112 3456 7890 189', 'account_holder_name' => 'Transit Mer SARL',
            'first_name' => 'Paul', 'last_name' => 'Mer', 'birth_date' => '1980-01-01', 'phone' => '+33600000000',
            'address_line1' => '1 quai', 'address_city' => 'Le Havre', 'address_postal_code' => '76600',
        ])->assertSessionHas('success');
        $this->assertEquals('acct_partner', $this->partner->fresh()->stripe_account_id);

        // Plus que le dû : refusé.
        $this->post(route('admin.delivery-partners.payout', $this->partner), ['amount' => 300000])->assertSessionHas('error');

        // 131 200 FCFA → 200 € viré sur l'IBAN.
        $this->post(route('admin.delivery-partners.payout', $this->partner), ['amount' => 131200])->assertSessionHas('success');
        $entry = PartnerLedgerEntry::where('type', 'payout')->firstOrFail();
        $this->assertEquals('processing', $entry->status);
        $this->assertEquals(200, (float) $entry->payout_amount);
        $this->assertEquals('EUR', $entry->payout_currency);
        $this->assertEquals(65600, PartnerLedgerService::balance($this->partner->id)['due']);

        $this->get(route('admin.delivery-partners.edit', $this->partner))
            ->assertOk()->assertSee('Compte partenaire')->assertSee('****6789');
    }

    public function test_failed_payout_restores_due(): void
    {
        $this->mockStripe();
        $this->partner->update(['stripe_account_id' => 'acct_partner', 'stripe_bank_country' => 'FR', 'stripe_external_last4' => '6789']);
        app(PartnerPayoutService::class)->payout($this->partner, 65600, $this->admin->id);
        $this->assertEquals(131200, PartnerLedgerService::balance($this->partner->id)['due']);

        $this->assertTrue(PartnerPayoutService::settle((object) ['id' => 'po_1', 'failure_message' => 'IBAN fermé'], false));
        $this->assertEquals('failed', PartnerLedgerEntry::where('type', 'payout')->value('status'));
        $this->assertEquals(196800, PartnerLedgerService::balance($this->partner->id)['due']);

        // Webhook inconnu : laissé aux retraits utilisateurs.
        $this->assertFalse(PartnerPayoutService::settle((object) ['id' => 'po_other'], true));
    }

    public function test_payout_refused_without_iban(): void
    {
        $this->mockStripe();
        $this->actingAs($this->admin);
        $this->post(route('admin.delivery-partners.payout', $this->partner), ['amount' => 1000])->assertSessionHas('error');
        $this->assertEquals(0, PartnerLedgerEntry::where('type', 'payout')->count());
    }

    public function test_manual_payout_lowers_due(): void
    {
        $this->actingAs($this->admin);
        $this->post(route('admin.delivery-partners.payouts.manual', $this->partner), [
            'amount' => 50000, 'payment_method' => 'mobile_money', 'paid_on' => today()->toDateString(), 'reference' => 'MM-123',
        ])->assertSessionHas('success');

        $this->assertEquals(146800, PartnerLedgerService::balance($this->partner->id)['due']);
        $this->get(route('admin.delivery-partners.index'))->assertOk()->assertSee('146 800 FCFA');
    }
}
