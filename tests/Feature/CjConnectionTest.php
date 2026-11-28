<?php

namespace Tests\Feature;

use App\Models\Supplier;
use App\Models\User;
use App\Services\Suppliers\Cj\CjApiException;
use App\Services\Suppliers\Cj\CjClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class CjConnectionTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://developers.cjdropshipping.com/api2.0/v1/';

    protected function setUp(): void
    {
        parent::setUp();
        config(['suppliers.cj.base_url' => rtrim(self::BASE, '/')]);
        Sleep::fake();
    }

    private function ok(mixed $data): array
    {
        return ['code' => 200, 'result' => true, 'message' => 'Success', 'data' => $data, 'requestId' => 'req-1', 'success' => true];
    }

    private function cjError(int $code, string $message = 'Erreur'): array
    {
        return ['code' => $code, 'result' => false, 'message' => $message, 'data' => null, 'requestId' => 'req-err', 'success' => false];
    }

    private function tokenData(string $token = 'tok-1'): array
    {
        return [
            'openId' => 123456789,
            'accessToken' => $token,
            'accessTokenExpiryDate' => now()->addDays(180)->toIso8601String(),
            'refreshToken' => 'refresh-1',
            'refreshTokenExpiryDate' => now()->addDays(360)->toIso8601String(),
        ];
    }

    private function cj(array $attributes = []): Supplier
    {
        $cj = Supplier::forCode(Supplier::CJ);
        $cj->forceFill(array_merge(['api_key' => 'CJ999@api@secret-key-1234'], $attributes))->save();

        return $cj;
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    public function test_token_is_obtained_with_the_api_key_and_stored_encrypted(): void
    {
        Http::fake([
            self::BASE.'authentication/getAccessToken' => Http::response($this->ok($this->tokenData())),
            self::BASE.'shopping/pay/getBalance' => Http::response($this->ok(['amount' => 250.5, 'freezeAmount' => 10])),
        ]);
        $cj = $this->cj();

        $data = (new CjClient($cj))->get('shopping/pay/getBalance');

        $this->assertSame(250.5, $data['amount']);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), 'getAccessToken') && $r['apiKey'] === 'CJ999@api@secret-key-1234');
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), 'getBalance') && $r->header('CJ-Access-Token') === ['tok-1']);

        $cj->refresh();
        $this->assertSame('tok-1', $cj->access_token);
        $this->assertSame('123456789', $cj->account_id);
        $raw = DB::table('suppliers')->where('id', $cj->id)->first();
        $this->assertNotSame('tok-1', $raw->access_token);
        $this->assertStringNotContainsString('secret-key', $raw->api_key);
    }

    public function test_a_valid_token_is_reused_without_authenticating_again(): void
    {
        Http::fake([self::BASE.'shopping/pay/getBalance' => Http::response($this->ok(['amount' => 5]))]);
        $cj = $this->cj(['access_token' => 'tok-ok', 'access_token_expires_at' => now()->addDays(30)]);

        (new CjClient($cj))->get('shopping/pay/getBalance');

        Http::assertSentCount(1);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'authentication'));
    }

    public function test_an_expiring_token_is_refreshed_with_the_refresh_token(): void
    {
        $data = $this->tokenData('tok-new');
        unset($data['openId']);
        Http::fake([
            self::BASE.'authentication/refreshAccessToken' => Http::response($this->ok($data)),
            self::BASE.'shopping/pay/getBalance' => Http::response($this->ok(['amount' => 5])),
        ]);
        $cj = $this->cj([
            'access_token' => 'tok-old', 'access_token_expires_at' => now()->addHours(2),
            'refresh_token' => 'refresh-old', 'refresh_token_expires_at' => now()->addDays(100),
            'account_id' => '42',
        ]);

        (new CjClient($cj))->get('shopping/pay/getBalance');

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), 'refreshAccessToken') && $r['refreshToken'] === 'refresh-old');
        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), 'getAccessToken'));
        $this->assertSame('tok-new', $cj->refresh()->access_token);
        $this->assertSame('42', $cj->account_id);
    }

    public function test_a_rejected_token_triggers_one_new_authentication(): void
    {
        Http::fake([
            self::BASE.'authentication/getAccessToken' => Http::response($this->ok($this->tokenData('tok-fresh'))),
            self::BASE.'shopping/pay/getBalance' => Http::sequence()
                ->push($this->cjError(CjApiException::INVALID_TOKEN, 'Authentication failed'))
                ->push($this->ok(['amount' => 7])),
        ]);
        $cj = $this->cj(['access_token' => 'tok-revoked', 'access_token_expires_at' => now()->addDays(30)]);

        $data = (new CjClient($cj))->get('shopping/pay/getBalance');

        $this->assertSame(7, $data['amount']);
        $this->assertSame('tok-fresh', $cj->refresh()->access_token);
    }

    public function test_too_many_requests_is_retried(): void
    {
        Http::fake([
            self::BASE.'shopping/pay/getBalance' => Http::sequence()
                ->push($this->cjError(CjApiException::TOO_MANY_REQUESTS, 'Too Many Requests'), 429)
                ->push($this->ok(['amount' => 3])),
        ]);
        $cj = $this->cj(['access_token' => 'tok', 'access_token_expires_at' => now()->addDays(30)]);

        $this->assertSame(3, (new CjClient($cj))->get('shopping/pay/getBalance')['amount']);
    }

    public function test_too_many_requests_is_reported_after_the_last_retry(): void
    {
        Http::fake([self::BASE.'*' => Http::response($this->cjError(CjApiException::TOO_MANY_REQUESTS), 429)]);
        $cj = $this->cj(['access_token' => 'tok', 'access_token_expires_at' => now()->addDays(30)]);
        try {
            (new CjClient($cj))->get('shopping/pay/getBalance');
            $this->fail('Exception attendue');
        } catch (CjApiException $e) {
            $this->assertSame(CjApiException::TOO_MANY_REQUESTS, $e->cjCode);
            Http::assertSentCount(1 + (int) config('suppliers.cj.retries'));
            $this->assertStringContainsString('req-err', $e->describe());
        }
    }

    public function test_calls_are_spaced_to_respect_the_rate_limit(): void
    {
        config(['suppliers.cj.requests_per_second' => 1]);
        Http::fake([self::BASE.'*' => Http::response($this->ok(['amount' => 1]))]);
        $cj = $this->cj(['access_token' => 'tok', 'access_token_expires_at' => now()->addDays(30)]);
        $client = new CjClient($cj);

        $client->get('shopping/pay/getBalance');
        $client->get('shopping/pay/getBalance');

        // Le second appel attend son créneau (jusqu'à une seconde après le premier).
        Sleep::assertSlept(fn ($duration) => $duration->totalMicroseconds > 0 && $duration->totalMicroseconds <= 1_000_000, 1);
    }

    public function test_admin_saves_the_key_tests_the_connection_and_activates_cj(): void
    {
        Http::fake([
            self::BASE.'authentication/getAccessToken' => Http::response($this->ok($this->tokenData())),
            self::BASE.'shopping/pay/getBalance' => Http::response($this->ok(['amount' => 80, 'freezeAmount' => 0])),
        ]);
        $admin = $this->admin();

        $this->actingAs($admin)->get('/admin/suppliers')->assertOk()->assertSee('CJdropshipping')->assertSee('Clé API manquante');

        $settings = ['margin_percent' => 30, 'fx_buffer_percent' => 4, 'balance_alert_usd' => 100, 'is_sandbox' => 1];
        $this->actingAs($admin)->put('/admin/suppliers/cj', $settings + ['api_key' => 'CJ1@api@abcd9876', 'is_active' => 1])
            ->assertSessionHas('error');
        $this->actingAs($admin)->put('/admin/suppliers/cj', $settings + ['api_key' => 'CJ1@api@abcd9876', 'is_active' => 0])
            ->assertRedirect('/admin/suppliers')->assertSessionHas('success');

        $this->actingAs($admin)->post('/admin/suppliers/cj/test')->assertSessionHas('success', fn ($m) => str_contains($m, '80,00 USD'));

        $cj = Supplier::forCode(Supplier::CJ);
        $this->assertTrue($cj->isConnected());
        $this->assertTrue($cj->balanceIsLow());

        $this->actingAs($admin)->put('/admin/suppliers/cj', $settings + ['is_active' => 1])->assertSessionHas('success');
        $cj->refresh();
        $this->assertTrue($cj->is_active);
        $this->assertSame('CJ1@api@abcd9876', $cj->api_key);
        $this->assertSame(30.0, (float) $cj->setting('margin_percent'));
        // Case non envoyée = décochée : les fiches importées arriveront inactives.
        $this->assertFalse($cj->setting('auto_publish'));

        $page = $this->actingAs($admin)->get('/admin/suppliers')->assertOk();
        $page->assertSee('9876')->assertDontSee('CJ1@api@abcd9876')->assertSee('Sous le seuil');
    }

    public function test_a_refused_key_is_explained_and_recorded(): void
    {
        Http::fake([self::BASE.'authentication/getAccessToken' => Http::response($this->cjError(CjApiException::USER_NOT_FOUND, 'User not find'))]);
        $this->cj();

        $this->actingAs($this->admin())->post('/admin/suppliers/cj/test')
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'My CJ API'));

        $cj = Supplier::forCode(Supplier::CJ);
        $this->assertFalse($cj->isConnected());
        $this->assertStringContainsString('1601000', $cj->last_error);
    }

    public function test_changing_the_key_forgets_the_old_tokens(): void
    {
        $this->cj(['access_token' => 'tok', 'access_token_expires_at' => now()->addDays(30), 'connected_at' => now()]);

        $this->actingAs($this->admin())->put('/admin/suppliers/cj', [
            'api_key' => 'CJ2@api@other', 'margin_percent' => 25, 'fx_buffer_percent' => 3, 'balance_alert_usd' => 100,
        ])->assertSessionHas('success');

        $cj = Supplier::forCode(Supplier::CJ);
        $this->assertNull($cj->access_token);
        $this->assertFalse($cj->isConnected());
    }

    public function test_managers_cannot_open_the_suppliers_page(): void
    {
        $manager = User::factory()->create([
            'role' => 'product_manager', 'roles' => ['product_manager'],
            'admin_permissions' => ['products.view', 'import_countries'],
        ]);

        $this->actingAs($manager)->get('/admin/suppliers')->assertForbidden();
        $this->actingAs($manager)->post('/admin/suppliers/cj/test')->assertForbidden();
    }

    public function test_status_command_reports_the_balance(): void
    {
        Http::fake([
            self::BASE.'authentication/getAccessToken' => Http::response($this->ok($this->tokenData())),
            self::BASE.'shopping/pay/getBalance' => Http::response($this->ok(['amount' => 500])),
        ]);
        $this->cj();

        $this->artisan('cj:status')->expectsOutputToContain('Connexion à CJ réussie')->assertSuccessful();
    }
}
