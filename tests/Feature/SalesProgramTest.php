<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Models\PackageSubscription;
use App\Models\SalesAgent;
use App\Models\SalesCommission;
use App\Models\Setting;
use App\Models\User;
use App\Models\WalletBalance;
use App\Models\WalletTransaction;
use App\Services\FcmService;
use App\Services\FirebaseMessagingService;
use App\Services\PackageSubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * « Devenir un commercial ASSO » : inscription depuis l'application, code limité au
 * premier forfait du vendeur, commission versée une fois dans le wallet du commercial.
 */
class SalesProgramTest extends TestCase
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

    private function enrolledAgent(): array
    {
        $user = User::factory()->create();
        $code = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/sales-program/enroll', ['accepted' => true])
            ->assertOk()
            ->json('data.code');

        return [$user, $code];
    }

    private function balanceOf(User $user): float
    {
        return (float) WalletBalance::where('user_id', $user->id)->where('currency', 'XAF')->value('balance');
    }

    public function test_program_shows_terms_and_current_rate_to_non_agent(): void
    {
        Setting::set('sales_program_terms', "Article 1\nArticle 2", 'text', 'commissions');
        Setting::set('sales_commission_rate', 12, 'string', 'commissions');

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->getJson('/api/v1/sales-program')
            ->assertOk()
            ->assertJsonPath('data.terms', "Article 1\nArticle 2")
            ->assertJsonPath('data.commission_rate', 12)
            ->assertJsonPath('data.is_agent', false)
            ->assertJsonPath('data.code', null);
    }

    public function test_enroll_requires_terms_acceptance(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/sales-program/enroll', [])->assertStatus(422);
        $this->actingAs($user, 'sanctum')->postJson('/api/v1/sales-program/enroll', ['accepted' => false])->assertStatus(422);

        $this->assertDatabaseCount('sales_agents', 0);
    }

    public function test_enroll_creates_agent_once_and_returns_same_code(): void
    {
        [$user, $code] = $this->enrolledAgent();

        $this->assertMatchesRegularExpression('/^ASSO-[A-Z2-9]{5}$/', $code);
        $agent = SalesAgent::firstOrFail();
        $this->assertSame($user->id, $agent->user_id);
        $this->assertSame(SalesAgent::SOURCE_SELF, $agent->source);
        $this->assertNotNull($agent->terms_accepted_at);
        $this->assertNull($agent->commission_rate);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/sales-program/enroll', ['accepted' => true])
            ->assertOk()
            ->assertJsonPath('data.code', $code);
        $this->assertDatabaseCount('sales_agents', 1);

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/sales-program')
            ->assertJsonPath('data.is_agent', true)
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.code', $code);
    }

    public function test_disabled_agent_cannot_get_code(): void
    {
        [$user] = $this->enrolledAgent();
        SalesAgent::firstOrFail()->update(['is_active' => false]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/sales-program/enroll', ['accepted' => true])
            ->assertForbidden()
            ->assertJsonPath('code', 'sales_agent_inactive');
    }

    public function test_enroll_requires_authentication(): void
    {
        $this->getJson('/api/v1/sales-program')->assertUnauthorized();
        $this->postJson('/api/v1/sales-program/enroll', ['accepted' => true])->assertUnauthorized();
    }

    public function test_first_subscription_with_code_credits_agent_wallet_once(): void
    {
        [$agentUser, $code] = $this->enrolledAgent();
        $vendor = $this->vendorWithBalance();

        $this->actingAs($vendor, 'sanctum')
            ->postJson('/api/v1/packages/subscribe', ['package_id' => $this->storagePackage()->id, 'payment_mode' => 'wallet', 'sales_code' => $code])
            ->assertCreated();

        $this->assertEquals(350, $this->balanceOf($agentUser));
        $commission = SalesCommission::firstOrFail();
        $this->assertSame(SalesCommission::STATUS_PAID, $commission->status);
        $this->assertStringStartsWith('WALLET-', $commission->payout_reference);

        $credit = WalletTransaction::where('user_id', $agentUser->id)->firstOrFail();
        $this->assertSame('credit', $credit->type);
        $this->assertEquals(350, $credit->amount);
        $this->assertSame('sales_commission', $credit->metadata['kind']);
        $this->assertSame($commission->id, $credit->metadata['sales_commission_id']);
    }

    public function test_code_is_refused_after_first_paid_subscription(): void
    {
        [, $code] = $this->enrolledAgent();
        $vendor = $this->vendorWithBalance(20000);
        $package = $this->storagePackage();

        $this->actingAs($vendor, 'sanctum')->getJson('/api/v1/packages')->assertJsonPath('can_use_sales_code', true);
        $this->actingAs($vendor, 'sanctum')->getJson('/api/v1/vendor/package/current')->assertJsonPath('can_use_sales_code', true);

        $this->actingAs($vendor, 'sanctum')
            ->postJson('/api/v1/packages/subscribe', ['package_id' => $package->id, 'payment_mode' => 'wallet'])
            ->assertCreated();

        $this->actingAs($vendor, 'sanctum')->getJson('/api/v1/packages')->assertJsonPath('can_use_sales_code', false);
        $this->actingAs($vendor, 'sanctum')->getJson('/api/v1/packages/certification')->assertJsonPath('can_use_sales_code', false);
        $this->actingAs($vendor, 'sanctum')->getJson('/api/v1/vendor/package/current')->assertJsonPath('can_use_sales_code', false);

        $this->actingAs($vendor, 'sanctum')
            ->postJson('/api/v1/packages/subscribe', ['package_id' => $package->id, 'payment_mode' => 'wallet', 'sales_code' => $code])
            ->assertStatus(422)
            ->assertJsonPath('code', 'invalid_sales_code')
            ->assertJsonPath('message', __('packages.sales_code_not_first'));

        $this->actingAs($vendor, 'sanctum')->getJson('/api/v1/sales-codes/'.$code)->assertNotFound();
        $this->assertDatabaseCount('sales_commissions', 0);
    }

    public function test_direct_payment_confirmed_twice_credits_wallet_once(): void
    {
        [$agentUser, $code] = $this->enrolledAgent();
        $agent = SalesAgent::firstOrFail();
        $vendor = User::factory()->create();
        $package = $this->storagePackage();
        $sub = PackageSubscription::create([
            'user_id' => $vendor->id, 'package_id' => $package->id,
            'sales_agent_id' => $agent->id, 'sales_code' => $code,
            'payment_method' => 'kpay_direct', 'status' => 'pending',
            'payment_reference' => 'pay_abc', 'amount_xaf' => 3500,
        ]);

        $service = app(PackageSubscriptionService::class);
        $service->confirm($sub);
        $service->confirm($sub);

        $this->assertEquals(350, $this->balanceOf($agentUser));
        $this->assertSame(1, WalletTransaction::where('user_id', $agentUser->id)->count());
    }

    public function test_vendor_brings_a_single_commission_even_with_two_pending_codes(): void
    {
        [$agentUser, $code] = $this->enrolledAgent();
        $agent = SalesAgent::firstOrFail();
        $vendor = User::factory()->create();
        $package = $this->storagePackage();
        $service = app(PackageSubscriptionService::class);

        foreach (['pay_1', 'pay_2'] as $reference) {
            $service->confirm(PackageSubscription::create([
                'user_id' => $vendor->id, 'package_id' => $package->id,
                'sales_agent_id' => $agent->id, 'sales_code' => $code,
                'payment_method' => 'kpay_direct', 'status' => 'pending',
                'payment_reference' => $reference, 'amount_xaf' => 3500,
            ]));
        }

        $this->assertDatabaseCount('sales_commissions', 1);
        $this->assertEquals(350, $this->balanceOf($agentUser));
    }

    public function test_rate_change_applies_to_self_enrolled_agents(): void
    {
        [$agentUser, $code] = $this->enrolledAgent();
        Setting::set('sales_commission_rate', 20, 'string', 'commissions');

        $this->actingAs($this->vendorWithBalance(), 'sanctum')
            ->postJson('/api/v1/packages/subscribe', ['package_id' => $this->storagePackage()->id, 'payment_mode' => 'wallet', 'sales_code' => $code])
            ->assertCreated();

        $this->assertEquals(700, $this->balanceOf($agentUser));
    }

    public function test_agent_cannot_use_own_code(): void
    {
        [$agentUser, $code] = $this->enrolledAgent();
        WalletBalance::updateOrCreate(['user_id' => $agentUser->id, 'currency' => 'XAF'], ['balance' => 10000, 'locked_balance' => 0]);

        $this->actingAs($agentUser, 'sanctum')
            ->postJson('/api/v1/packages/subscribe', ['package_id' => $this->storagePackage()->id, 'payment_mode' => 'wallet', 'sales_code' => $code])
            ->assertStatus(422)
            ->assertJsonPath('code', 'invalid_sales_code');
    }

    public function test_admin_edits_program_terms(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['role' => 'admin'])->saveQuietly();

        $this->actingAs($admin)
            ->post(route('admin.sales.agents.program-terms'), ['sales_program_terms' => 'Nouvelles modalités'])
            ->assertRedirect();

        $this->actingAs($admin)->get(route('admin.sales.agents.index'))->assertOk()->assertSee('Nouvelles modalités');
        $this->actingAs(User::factory()->create(), 'sanctum')->getJson('/api/v1/sales-program')
            ->assertJsonPath('data.terms', 'Nouvelles modalités');
    }
}
