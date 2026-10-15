<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Models\PackageSubscription;
use App\Models\SalesAgent;
use App\Models\SalesCommission;
use App\Models\Setting;
use App\Models\User;
use App\Models\WalletBalance;
use App\Services\FcmService;
use App\Services\FirebaseMessagingService;
use App\Services\PackageSubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P6 — Codes commerciaux et traçabilité des souscriptions de forfaits.
 */
class SalesCommissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(FirebaseMessagingService::class, fn ($m) => $m->shouldReceive('sendToUser')->andReturn([]));
        $this->mock(FcmService::class, fn ($m) => $m->shouldIgnoreMissing());
    }

    private function vendorWithBalance(float $balance = 10000): User
    {
        $u = User::factory()->create();
        WalletBalance::updateOrCreate(['user_id' => $u->id, 'currency' => 'XAF'], ['balance' => $balance, 'locked_balance' => 0]);

        return $u;
    }

    private function storagePackage(): Package
    {
        return Package::create([
            'type' => 'storage', 'name' => 'Stockage Business', 'price' => 3500,
            'duration_days' => 30, 'storage_size_mb' => 1024, 'is_active' => true,
        ]);
    }

    private function agent(array $attrs = []): SalesAgent
    {
        return SalesAgent::create(array_merge(['first_name' => 'Awa', 'last_name' => 'Ndiaye', 'code' => 'asso-awa1'], $attrs));
    }

    private function admin(): User
    {
        $u = User::factory()->create();
        $u->forceFill(['role' => 'admin'])->saveQuietly();

        return $u;
    }

    public function test_code_is_normalized_and_generated_when_missing(): void
    {
        $this->assertSame('ASSO-AWA1', $this->agent()->code);
        $generated = SalesAgent::create(['first_name' => 'Jean', 'last_name' => 'Kamga']);
        $this->assertMatchesRegularExpression('/^ASSO-[A-Z2-9]{5}$/', $generated->code);
    }

    public function test_wallet_subscription_with_code_records_due_commission_with_default_rate(): void
    {
        $vendor = $this->vendorWithBalance();
        $agent = $this->agent();
        $package = $this->storagePackage();

        $this->actingAs($vendor, 'sanctum')
            ->postJson('/api/v1/packages/subscribe', ['package_id' => $package->id, 'payment_mode' => 'wallet', 'sales_code' => ' asso-awa1 '])
            ->assertCreated()
            ->assertJsonPath('sales_code', 'ASSO-AWA1')
            ->assertJsonPath('data.sales_code', 'ASSO-AWA1');

        $sub = PackageSubscription::firstOrFail();
        $this->assertSame($agent->id, $sub->sales_agent_id);

        $c = SalesCommission::firstOrFail();
        $this->assertSame($agent->id, $c->sales_agent_id);
        $this->assertSame($vendor->id, $c->vendor_id);
        $this->assertSame('Stockage Business', $c->package_name);
        $this->assertEquals(3500, $c->amount_paid_xaf);
        $this->assertEquals(10, $c->rate);
        $this->assertEquals(350, $c->commission_amount);
        $this->assertSame('due', $c->status);
        $this->assertSame($sub->payment_reference, $c->transaction_reference);
        $this->assertNotNull($c->sold_at);
    }

    public function test_agent_specific_rate_overrides_setting(): void
    {
        Setting::set('sales_commission_rate', 5, 'string', 'commissions');
        $vendor = $this->vendorWithBalance();
        $this->agent(['commission_rate' => 12.5]);

        $this->actingAs($vendor, 'sanctum')
            ->postJson('/api/v1/packages/subscribe', ['package_id' => $this->storagePackage()->id, 'payment_mode' => 'wallet', 'sales_code' => 'ASSO-AWA1'])
            ->assertCreated();

        $this->assertEquals(438, SalesCommission::firstOrFail()->commission_amount); // 3500 × 12,5 % arrondi
    }

    public function test_subscription_without_code_is_unchanged(): void
    {
        $vendor = $this->vendorWithBalance();

        $this->actingAs($vendor, 'sanctum')
            ->postJson('/api/v1/packages/subscribe', ['package_id' => $this->storagePackage()->id, 'payment_mode' => 'wallet'])
            ->assertCreated()
            ->assertJsonPath('sales_code', null);

        $this->assertDatabaseCount('sales_commissions', 0);
    }

    public function test_invalid_inactive_or_own_code_is_refused_before_payment(): void
    {
        $vendor = $this->vendorWithBalance();
        $package = $this->storagePackage();
        $this->agent(['code' => 'OFF-1234', 'is_active' => false]);
        $this->agent(['code' => 'SELF-1234', 'user_id' => $vendor->id]);

        foreach (['NOPE-0000', 'OFF-1234', 'SELF-1234'] as $code) {
            $this->actingAs($vendor, 'sanctum')
                ->postJson('/api/v1/packages/subscribe', ['package_id' => $package->id, 'payment_mode' => 'wallet', 'sales_code' => $code])
                ->assertStatus(422)
                ->assertJsonPath('code', 'invalid_sales_code');
        }

        $this->assertDatabaseCount('package_subscriptions', 0);
        $this->assertEquals(10000, $vendor->fresh()->kpayBalanceFor('XAF'));
    }

    public function test_direct_payment_commission_created_once_on_confirmation_only(): void
    {
        $vendor = User::factory()->create();
        $agent = $this->agent();
        $package = $this->storagePackage();
        $sub = PackageSubscription::create([
            'user_id' => $vendor->id, 'package_id' => $package->id,
            'sales_agent_id' => $agent->id, 'sales_code' => $agent->code,
            'payment_method' => 'kpay_direct', 'status' => 'pending',
            'payment_reference' => 'pay_abc', 'amount_xaf' => 3500,
            'metadata' => ['package_name' => $package->name],
        ]);

        $this->assertDatabaseCount('sales_commissions', 0);

        $service = app(PackageSubscriptionService::class);
        $service->confirm($sub);
        $service->confirm($sub);

        $this->assertDatabaseCount('sales_commissions', 1);
        $c = SalesCommission::firstOrFail();
        $this->assertSame('pay_abc', $c->transaction_reference);
        $this->assertSame('kpay_direct', $c->payment_method);
    }

    public function test_failed_direct_payment_creates_no_commission(): void
    {
        $vendor = User::factory()->create();
        $agent = $this->agent();
        $package = $this->storagePackage();
        $sub = PackageSubscription::create([
            'user_id' => $vendor->id, 'package_id' => $package->id, 'sales_agent_id' => $agent->id,
            'sales_code' => $agent->code, 'payment_method' => 'kpay_direct', 'status' => 'pending', 'amount_xaf' => 3500,
        ]);

        app(PackageSubscriptionService::class)->fail($sub);

        $this->assertDatabaseCount('sales_commissions', 0);
    }

    public function test_code_check_endpoint(): void
    {
        $vendor = User::factory()->create();
        $this->agent();

        $this->actingAs($vendor, 'sanctum')->getJson('/api/v1/sales-codes/asso-awa1')
            ->assertOk()
            ->assertJsonPath('data.code', 'ASSO-AWA1')
            ->assertJsonPath('data.agent_display_name', 'Awa N.');

        $this->actingAs($vendor, 'sanctum')->getJson('/api/v1/sales-codes/UNKNOWN')->assertNotFound();
    }

    public function test_code_check_requires_authentication(): void
    {
        $this->getJson('/api/v1/sales-codes/ASSO-AWA1')->assertUnauthorized();
    }

    public function test_admin_marks_commissions_paid_and_cancels(): void
    {
        $admin = $this->admin();
        $agent = $this->agent();
        $package = $this->storagePackage();
        $service = app(PackageSubscriptionService::class);
        $service->payWithWallet($this->vendorWithBalance(), $package, $agent);
        $service->payWithWallet($this->vendorWithBalance(), $package, $agent);
        [$c1, $c2] = SalesCommission::orderBy('id')->get()->all();

        $this->actingAs($admin)
            ->post(route('admin.sales.commissions.mark-paid'), ['ids' => [$c1->id], 'payout_reference' => 'OM-778899'])
            ->assertRedirect();
        $c1->refresh();
        $this->assertSame('paid', $c1->status);
        $this->assertSame('OM-778899', $c1->payout_reference);
        $this->assertSame($admin->id, $c1->paid_by);

        $this->actingAs($admin)
            ->post(route('admin.sales.commissions.cancel', $c2), ['cancel_reason' => 'Vente litigieuse'])
            ->assertRedirect();
        $this->assertSame('cancelled', $c2->fresh()->status);

        // Une commission payée ne peut plus être annulée.
        $this->actingAs($admin)->post(route('admin.sales.commissions.cancel', $c1), ['cancel_reason' => 'x']);
        $this->assertSame('paid', $c1->fresh()->status);
    }

    public function test_admin_pages_and_export_render(): void
    {
        $admin = $this->admin();
        $agent = $this->agent();
        app(PackageSubscriptionService::class)->payWithWallet($this->vendorWithBalance(), $this->storagePackage(), $agent);

        $this->actingAs($admin)->get(route('admin.sales.agents.index'))->assertOk()->assertSee('ASSO-AWA1');
        $this->actingAs($admin)->get(route('admin.sales.agents.show', $agent))->assertOk()->assertSee('Stockage Business');
        $this->actingAs($admin)->get(route('admin.sales.agents.create'))->assertOk();
        $this->actingAs($admin)->get(route('admin.sales.agents.edit', $agent))->assertOk();
        $this->actingAs($admin)->get(route('admin.sales.commissions.index', ['status' => 'due']))->assertOk()->assertSee('À payer');

        $csv = $this->actingAs($admin)->get(route('admin.sales.commissions.export'))->assertOk()->streamedContent();
        $this->assertStringContainsString('ASSO-AWA1', $csv);
        $this->assertStringContainsString('350', $csv);
    }

    public function test_admin_creates_agent_with_generated_code_and_rejects_duplicate(): void
    {
        $admin = $this->admin();
        $this->agent();

        $this->actingAs($admin)->post(route('admin.sales.agents.store'), [
            'first_name' => 'Paul', 'last_name' => 'Biya', 'is_active' => '1',
        ])->assertRedirect();
        $this->assertMatchesRegularExpression('/^ASSO-/', SalesAgent::where('first_name', 'Paul')->value('code'));

        $this->actingAs($admin)->post(route('admin.sales.agents.store'), [
            'first_name' => 'X', 'last_name' => 'Y', 'code' => 'asso-awa1',
        ])->assertSessionHasErrors('code');
    }

    public function test_vendor_admin_page_shows_sales_code_of_subscriptions(): void
    {
        $admin = $this->admin();
        $vendor = $this->vendorWithBalance();
        app(PackageSubscriptionService::class)->payWithWallet($vendor, $this->storagePackage(), $this->agent());

        $this->actingAs($admin)->get(route('admin.users.show', $vendor))
            ->assertOk()
            ->assertSee('Forfaits souscrits')
            ->assertSee('ASSO-AWA1')
            ->assertSee('Awa Ndiaye');
    }

    public function test_default_rate_is_editable_from_commissions_settings_tab(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('admin.settings.index'))->assertOk()->assertSee('sales_commission_rate', false);

        $this->actingAs($admin)->put(route('admin.settings.commissions.update'), [
            'default_sale_commission_rate' => 0,
            'diaspo_commission_rate' => 5,
            'sales_commission_rate' => 15,
        ])->assertRedirect();

        $this->assertEquals(15, app(\App\Services\SalesCommissionService::class)->defaultRate());
    }
}
