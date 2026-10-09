<?php

namespace Tests\Feature;

use App\Models\PlatformWithdrawal;
use App\Models\ServiceConfiguration;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\ElgioPayService;
use App\Services\MobileMoneyGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ElgioPay : prestataire Mobile Money Cameroun, exclusif avec KPay.
 *
 * Couvre le panneau admin (exclusivité), l'exposition au mobile, la recharge et
 * le retrait wallet de bout en bout, et le webhook (signature, dédoublonnage,
 * statut toujours relu auprès de l'API).
 */
class ElgioPayIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private const API = 'https://api.elgiopay.com/api/v1';
    private const PK = 'pk_live_test_public';
    private const SK = 'sk_live_test_secret';

    /** Statut renvoyé par le faux GET /payments/{id} et /payouts/{id}. */
    private string $remoteStatus = 'pending';

    protected function setUp(): void
    {
        parent::setUp();

        ServiceConfiguration::setConfig('kpay', ['api_key' => 'kpay_k', 'secret_key' => 'kpay_s', 'base_url' => 'https://kpay.test'], false);
        ServiceConfiguration::setConfig('elgiopay', [
            'mode' => 'live',
            'public_key' => self::PK,
            'secret_key' => self::SK,
        ], true);

        Http::fake(function (HttpRequest $request) {
            $url = $request->url();
            $method = $request->method();

            if ($method === 'POST' && $url === self::API . '/payments') {
                return Http::response(['success' => true, 'transaction_id' => 'TX123', 'status' => 'pending', 'message' => 'ok']);
            }
            if (preg_match('#/payments/TX123(/verify)?$#', $url)) {
                return Http::response(['transaction_id' => 'TX123', 'status' => $this->remoteStatus, 'reference' => $this->lastReference ?? null]);
            }
            if ($method === 'POST' && $url === self::API . '/payouts') {
                return Http::response(['success' => true, 'data' => ['payout_id' => 'PO999', 'status' => 'pending']]);
            }
            if (str_ends_with($url, '/payouts/PO999')) {
                return Http::response(['payout_id' => 'PO999', 'status' => $this->remoteStatus, 'failure_reason' => $this->remoteStatus === 'failed' ? 'Numéro invalide' : null]);
            }
            if (str_starts_with($url, 'https://kpay.test/')) {
                return Http::response(['status' => 'COMPLETED']);
            }
            if (str_ends_with($url, '/balance')) {
                return Http::response(['balance' => '1000', 'available_balance' => '900', 'reserved_balance' => '100', 'currency' => 'XAF']);
            }
            return Http::response(['message' => 'unexpected ' . $method . ' ' . $url], 500);
        });
    }

    private ?string $lastReference = null;

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->forceFill(['role' => 'admin'])->saveQuietly();
        return $user;
    }

    private function signedWebhook(array $payload, string $secret = self::SK)
    {
        $raw = json_encode($payload);
        $t = time();
        $sig = 't=' . $t . ',v1=' . hash_hmac('sha256', $t . '.' . $raw, $secret);

        return $this->call('POST', '/api/v1/elgiopay/callback', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_ELGIOPAY_SIGNATURE' => $sig,
        ], $raw);
    }

    // ── Admin ─────────────────────────────────────────────────────────

    public function test_admin_page_shows_elgiopay_tab(): void
    {
        $this->actingAs($this->admin())
            ->get(route('admin.settings.payments'))
            ->assertOk()
            ->assertSee('elgiopay_public_key', false)
            ->assertSee('/api/v1/elgiopay/callback', false);
    }

    public function test_enabling_elgiopay_disables_kpay_and_vice_versa(): void
    {
        $admin = $this->admin();
        ServiceConfiguration::toggleService('kpay', true);
        ServiceConfiguration::toggleService('elgiopay', false);

        $this->actingAs($admin)->put(route('admin.settings.payments.update'), [
            '_form' => 'elgiopay',
            'elgiopay_enabled' => 1,
            'elgiopay_public_key' => 'pk_live_new',
            'elgiopay_webhook_url' => 'https://example.ngrok-free.dev/api/v1/elgiopay/callback',
        ])->assertRedirect(route('admin.settings.payments'));

        $this->assertTrue(ServiceConfiguration::isActive('elgiopay'));
        $this->assertFalse(ServiceConfiguration::isActive('kpay'));
        $raw = ServiceConfiguration::getRawConfig('elgiopay');
        $this->assertSame('pk_live_new', $raw['public_key']);
        $this->assertSame(self::SK, $raw['secret_key'], 'secret laissé vide = conservé');

        $this->actingAs($admin)->put(route('admin.settings.payments.update'), [
            '_form' => 'kpay',
            'kpay_enabled' => 1,
        ])->assertRedirect(route('admin.settings.payments'));

        $this->assertTrue(ServiceConfiguration::isActive('kpay'));
        $this->assertFalse(ServiceConfiguration::isActive('elgiopay'));
        $this->assertSame('pk_live_new', ServiceConfiguration::getRawConfig('elgiopay')['public_key'], 'clés conservées à la désactivation');
    }

    public function test_connection_test_uses_public_key_as_bearer(): void
    {
        $this->actingAs($this->admin())
            ->postJson(route('admin.settings.payments.test-elgiopay'))
            ->assertOk()
            ->assertJsonPath('success', true);

        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/balance')
            && $r->hasHeader('Authorization', 'Bearer ' . self::PK));
    }

    // ── Exposition au mobile ──────────────────────────────────────────

    public function test_payment_methods_expose_only_active_gateway_scope(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $res = $this->getJson('/api/v1/payments/methods?amount=5000&currency=XAF')->assertOk();
        $mm = collect($res->json('data.methods'))->firstWhere('code', 'kpay');

        $this->assertTrue($mm['enabled']);
        $this->assertSame('elgiopay', $mm['gateway']);
        $this->assertSame(['MTN_MOMO_CMR', 'ORANGE_CMR'], $mm['providers']);
        $this->assertSame(['CMR'], $mm['countries']);
        $this->assertSame('XAF', $mm['target_currency']);

        // Bascule sur KPay : tout le catalogue multi-pays revient.
        ServiceConfiguration::toggleService('elgiopay', false);
        ServiceConfiguration::toggleService('kpay', true);
        $res = $this->getJson('/api/v1/payments/methods?amount=5000&currency=XAF')->assertOk();
        $mm = collect($res->json('data.methods'))->firstWhere('code', 'kpay');
        $this->assertSame('kpay', $mm['gateway']);
        $this->assertContains('MTN_MOMO_CIV', $mm['providers']);
    }

    // ── Recharge wallet ───────────────────────────────────────────────

    public function test_recharge_goes_through_elgiopay_and_webhook_credits_once(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $res = $this->postJson('/api/v1/wallet/recharge', [
            'amount' => 5000,
            'payment_method' => 'kpay',
            'provider' => 'ORANGE_CMR',
            'phone_number' => '690 00 00 00',
        ])->assertOk()->assertJsonPath('success', true);

        $tx = WalletTransaction::findOrFail($res->json('data.transaction_id'));
        $this->assertSame('elgiopay:TX123', $tx->metadata['provider_reference']);
        $this->assertSame('pending', $tx->status, 'suivi asynchrone : toujours en attente côté opérateur');

        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'POST' && $r->url() === self::API . '/payments'
            && $r['channel_code'] === 'ORANGE_CMR'
            && !isset($r['payment_method'])
            && $r['customer_phone'] === '690000000'
            && $r['amount'] === 5000
            && $r['currency'] === 'XAF'
            && $r['reference'] === 'WALLET-' . $tx->id);

        // Le client valide : ElgioPay notifie.
        $this->remoteStatus = 'completed';
        $payload = ['id' => 'evt_1', 'event' => 'payment.completed', 'data' => ['transaction_id' => 'TX123', 'reference' => 'WALLET-' . $tx->id, 'status' => 'completed']];
        $this->signedWebhook($payload)->assertOk();
        $this->signedWebhook($payload)->assertOk()->assertJsonPath('message', 'Already received');

        $this->assertSame('completed', $tx->fresh()->status);
        $this->assertSame(5000.0, $user->fresh()->kpayAvailableFor('XAF'));
    }

    /** Cas réel observé en live : ElgioPay ne renvoie jamais notre référence. */
    public function test_webhook_without_reference_resolves_wallet_by_stored_id(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $txId = $this->postJson('/api/v1/wallet/recharge', [
            'amount' => 100, 'payment_method' => 'kpay', 'provider' => 'ORANGE_CMR', 'phone_number' => '658895572',
        ])->json('data.transaction_id');

        $this->remoteStatus = 'failed';
        $this->signedWebhook(['id' => 'evt_live', 'event' => 'payment.failed', 'data' => ['transaction_id' => 'TX123', 'status' => 'failed']])
            ->assertOk();

        $tx = WalletTransaction::find($txId);
        $this->assertSame('failed', $tx->status);
        $this->assertSame(0.0, $user->fresh()->kpayAvailableFor('XAF'));
    }

    public function test_recharge_with_non_cameroon_operator_is_refused_when_elgiopay_active(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/wallet/recharge', [
            'amount' => 5000,
            'payment_method' => 'kpay',
            'provider' => 'MTN_MOMO_CIV',
            'phone_number' => '0700000000',
        ])->assertStatus(400)->assertJsonPath('success', false);

        $this->assertSame(0, WalletTransaction::count());
        Http::assertNothingSent();
    }

    public function test_forged_webhook_cannot_credit_when_api_says_pending(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $txId = $this->postJson('/api/v1/wallet/recharge', [
            'amount' => 2000, 'payment_method' => 'kpay', 'provider' => 'MTN_MOMO_CMR', 'phone_number' => '670000000',
        ])->json('data.transaction_id');

        // Corps « completed » non signé, mais l'API dit toujours pending.
        $this->postJson('/api/v1/elgiopay/callback', [
            'event' => 'payment.completed',
            'data' => ['transaction_id' => 'TX123', 'reference' => 'WALLET-' . $txId, 'status' => 'completed'],
        ])->assertOk();

        $this->assertSame('pending', WalletTransaction::find($txId)->status);
        $this->assertSame(0.0, $user->fresh()->kpayAvailableFor('XAF'));
    }

    public function test_invalid_signature_rejected_when_dedicated_secret_configured(): void
    {
        ServiceConfiguration::setConfig('elgiopay', [
            'public_key' => self::PK, 'secret_key' => self::SK, 'webhook_secret' => 'whsec_dedicated',
        ], true);

        $this->signedWebhook(['event' => 'payment.completed', 'data' => ['transaction_id' => 'TX123']], 'wrong')
            ->assertStatus(401);
        $this->signedWebhook(['id' => 'evt_ok', 'event' => 'payment.pending', 'data' => ['transaction_id' => 'TX123', 'reference' => 'UNKNOWN']], 'whsec_dedicated')
            ->assertOk();
    }

    // ── Retrait wallet ────────────────────────────────────────────────

    public function test_withdrawal_via_elgiopay_refunds_on_failed_payout(): void
    {
        $user = User::factory()->create(['first_name' => 'Jean', 'last_name' => 'Mballa']);
        $user->creditKpay('XAF', 10000);
        Sanctum::actingAs($user);

        $res = $this->postJson('/api/v1/wallet/withdraw/kpay', [
            'amount' => 4000,
            'provider' => 'MTN_MOMO_CMR',
            'phone' => '+237 670 00 00 00',
        ])->assertOk()->assertJsonPath('success', true);

        $withdrawal = PlatformWithdrawal::findOrFail($res->json('data.withdrawal_id'));
        $this->assertSame('elgiopay:PO999', $withdrawal->kpay_reference);
        $this->assertSame(6000.0, $user->fresh()->kpayAvailableFor('XAF'));

        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'POST' && $r->url() === self::API . '/payouts'
            && $r['payout_method'] === 'mtn_mobile_money'
            && $r['recipient_phone'] === '670000000'
            && $r['recipient_name'] === 'Jean Mballa'
            && $r['reference'] === 'WITHDRAW-' . $withdrawal->id);

        $this->remoteStatus = 'failed';
        $this->signedWebhook(['id' => 'evt_po', 'event' => 'payout.failed', 'data' => ['payout_id' => 'PO999', 'reference' => 'WITHDRAW-' . $withdrawal->id, 'status' => 'failed']])
            ->assertOk();

        $withdrawal->refresh();
        $this->assertSame('failed', $withdrawal->status);
        $this->assertSame('ELGIOPAY_FAILED', $withdrawal->failure_code);
        $this->assertSame(10000.0, $user->fresh()->kpayAvailableFor('XAF'), 'fonds restitués');
    }

    // ── Routage / normalisation ───────────────────────────────────────

    public function test_in_flight_kpay_reference_still_polled_at_kpay_after_switch(): void
    {
        $gateway = app(MobileMoneyGateway::class);
        $this->assertSame('elgiopay', $gateway->activeGateway());

        $result = $gateway->checkPaymentStatus('pay_abc');

        $this->assertSame('kpay', $result['gateway']);
        $this->assertSame('COMPLETED', $result['status']);
    }

    public function test_status_and_phone_normalisation(): void
    {
        $this->assertSame('COMPLETED', ElgioPayService::normalizeStatus('completed'));
        $this->assertSame('FAILED', ElgioPayService::normalizeStatus('expired'));
        $this->assertSame('CANCELLED', ElgioPayService::normalizeStatus('cancelled'));
        $this->assertSame('PENDING', ElgioPayService::normalizeStatus('processing'));

        // 9 chiffres sans indicatif : avec +237 l'opérateur refuse (constaté en prod).
        $this->assertSame('670000000', ElgioPayService::normalizePhone('670000000'));
        $this->assertSame('670000000', ElgioPayService::normalizePhone('237670000000'));
        $this->assertSame('670000000', ElgioPayService::normalizePhone('+237 670 00 00 00'));
        $this->assertSame('670000000', ElgioPayService::normalizePhone('00237670000000'));
    }
}
