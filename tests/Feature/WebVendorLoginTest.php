<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\Shop;
use App\Models\User;
use App\Models\WebLoginSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class WebVendorLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::set('web_vendor_login_enabled', true, 'boolean', 'links');
    }

    private function vendor(): User
    {
        $vendor = User::factory()->create(['role' => 'vendeur']);
        Shop::create(['user_id' => $vendor->id, 'name' => 'Boutique '.$vendor->id, 'status' => 'active', 'verified_at' => now()]);

        return $vendor;
    }

    /** @return array{id: string, secret: string, code: string} */
    private function browserSession(): array
    {
        $session = $this->withHeader('User-Agent', 'Mozilla/5.0 (Windows NT 10.0) Chrome/120.0 Safari/537')
            ->postJson('/api/v1/web-login/sessions')
            ->assertCreated()
            ->json('session');

        $this->assertStringStartsWith('asso-vendor-web:', $session['qr']);

        return [
            'id' => $session['id'],
            'secret' => $session['secret'],
            'code' => substr($session['qr'], strlen('asso-vendor-web:')),
        ];
    }

    private function asPhone(User $user): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($user->createToken('mobile-app')->plainTextToken);
    }

    private function poll(array $session, ?string $secret = null)
    {
        $this->app['auth']->forgetGuards();

        return $this->withoutToken()->postJson("/api/v1/web-login/sessions/{$session['id']}/poll", [
            'secret' => $secret ?? $session['secret'],
        ]);
    }

    private function signIn(User $vendor): string
    {
        $session = $this->browserSession();
        $this->asPhone($vendor)->postJson('/api/v1/web-login/scan', ['code' => $session['code']])->assertOk();
        $this->asPhone($vendor)->postJson('/api/v1/web-login/approve', ['code' => $session['code']])->assertOk();

        return $this->poll($session)->assertOk()->json('token');
    }

    private function asBrowser(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    public function test_le_parcours_complet_connecte_le_navigateur_avec_un_jeton_limite(): void
    {
        $vendor = $this->vendor();
        $session = $this->browserSession();

        $this->poll($session)->assertJsonPath('status', 'pending');

        $this->asPhone($vendor)->postJson('/api/v1/web-login/scan', ['code' => $session['code']])
            ->assertOk()
            ->assertJsonPath('session.browser', 'Chrome')
            ->assertJsonPath('session.platform', 'Windows');

        $this->poll($session)->assertJsonPath('status', 'scanned')->assertJsonMissingPath('token');

        $this->asPhone($vendor)->postJson('/api/v1/web-login/approve', ['code' => $session['code']])->assertOk();

        $response = $this->poll($session)->assertOk()->assertJsonPath('status', 'approved');
        $this->assertSame($vendor->id, $response->json('user.id'));

        $token = PersonalAccessToken::findToken($response->json('token'));
        $this->assertSame(['vendor-web'], $token->abilities);
        $this->assertSame($vendor->id, $token->tokenable_id);
        $this->assertTrue($token->expires_at->between(now()->addHours(11), now()->addHours(13)));
    }

    public function test_le_jeton_n_est_remis_qu_une_seule_fois(): void
    {
        $vendor = $this->vendor();
        $session = $this->browserSession();
        $this->asPhone($vendor)->postJson('/api/v1/web-login/scan', ['code' => $session['code']]);
        $this->asPhone($vendor)->postJson('/api/v1/web-login/approve', ['code' => $session['code']]);

        $this->poll($session)->assertJsonPath('status', 'approved');
        $this->poll($session)->assertJsonPath('status', 'expired')->assertJsonMissingPath('token');
        $this->assertSame(1, $vendor->tokens()->where('name', 'vendor-web')->count());
    }

    public function test_un_mauvais_secret_ne_recupere_rien(): void
    {
        $vendor = $this->vendor();
        $session = $this->browserSession();
        $this->asPhone($vendor)->postJson('/api/v1/web-login/scan', ['code' => $session['code']]);
        $this->asPhone($vendor)->postJson('/api/v1/web-login/approve', ['code' => $session['code']]);

        $this->poll($session, 'mauvais-secret')->assertNotFound()->assertJsonMissingPath('token');
        $this->assertSame(0, $vendor->tokens()->where('name', 'vendor-web')->count());
    }

    public function test_les_secrets_ne_sont_pas_stockes_en_clair(): void
    {
        $session = $this->browserSession();
        $row = WebLoginSession::firstOrFail();

        $this->assertNotSame($session['secret'], $row->secret_hash);
        $this->assertNotSame($session['code'], $row->code_hash);
        $this->assertArrayNotHasKey('secret_hash', $row->toArray());
    }

    public function test_un_qr_expire_est_refuse(): void
    {
        $vendor = $this->vendor();
        $session = $this->browserSession();

        $this->travel(121)->seconds();

        $this->asPhone($vendor)->postJson('/api/v1/web-login/scan', ['code' => $session['code']])->assertStatus(410);
        $this->poll($session)->assertJsonPath('status', 'expired');
    }

    public function test_un_jeton_non_recupere_a_temps_est_perdu(): void
    {
        $vendor = $this->vendor();
        $session = $this->browserSession();
        $this->asPhone($vendor)->postJson('/api/v1/web-login/scan', ['code' => $session['code']]);
        $this->asPhone($vendor)->postJson('/api/v1/web-login/approve', ['code' => $session['code']]);

        $this->travel(61)->seconds();

        $this->poll($session)->assertJsonPath('status', 'expired')->assertJsonMissingPath('token');
    }

    public function test_le_refus_du_telephone_arrive_au_navigateur(): void
    {
        $vendor = $this->vendor();
        $session = $this->browserSession();
        $this->asPhone($vendor)->postJson('/api/v1/web-login/scan', ['code' => $session['code']]);
        $this->asPhone($vendor)->postJson('/api/v1/web-login/reject', ['code' => $session['code']])->assertOk();

        $this->poll($session)->assertJsonPath('status', 'rejected')->assertJsonMissingPath('token');
        $this->asPhone($vendor)->postJson('/api/v1/web-login/approve', ['code' => $session['code']])->assertStatus(410);
    }

    public function test_un_autre_compte_ne_peut_ni_voler_ni_confirmer_un_qr_deja_lu(): void
    {
        $vendor = $this->vendor();
        $other = $this->vendor();
        $session = $this->browserSession();
        $this->asPhone($vendor)->postJson('/api/v1/web-login/scan', ['code' => $session['code']])->assertOk();

        $this->asPhone($other)->postJson('/api/v1/web-login/scan', ['code' => $session['code']])->assertStatus(410);
        $this->asPhone($other)->postJson('/api/v1/web-login/approve', ['code' => $session['code']])->assertStatus(410);
    }

    public function test_confirmer_sans_avoir_scanne_est_refuse(): void
    {
        $session = $this->browserSession();

        $this->asPhone($this->vendor())->postJson('/api/v1/web-login/approve', ['code' => $session['code']])->assertStatus(410);
    }

    public function test_un_compte_sans_role_vendeur_ou_sans_boutique_est_refuse(): void
    {
        $session = $this->browserSession();
        $client = User::factory()->create(['role' => 'client']);
        $noShop = User::factory()->create(['role' => 'vendeur']);

        $this->asPhone($client)->postJson('/api/v1/web-login/scan', ['code' => $session['code']])->assertForbidden();
        $this->asPhone($noShop)->postJson('/api/v1/web-login/scan', ['code' => $session['code']])->assertForbidden();
        $this->asPhone($client)->getJson('/api/v1/web-login/availability')->assertJsonPath('available', false);
    }

    public function test_seuls_les_comptes_de_test_entrent_quand_la_liste_est_renseignee(): void
    {
        $tester = $this->vendor();
        $other = $this->vendor();
        Setting::set('web_vendor_test_user_ids', (string) $tester->id, 'text', 'links');
        $session = $this->browserSession();

        $this->asPhone($other)->getJson('/api/v1/web-login/availability')->assertJsonPath('available', false);
        $this->asPhone($other)->postJson('/api/v1/web-login/scan', ['code' => $session['code']])->assertForbidden();

        $this->asPhone($tester)->getJson('/api/v1/web-login/availability')->assertJsonPath('available', true);
        $this->asPhone($tester)->postJson('/api/v1/web-login/scan', ['code' => $session['code']])->assertOk();
    }

    public function test_le_jeton_web_est_refuse_sur_l_argent_et_le_compte(): void
    {
        $token = $this->signIn($this->vendor());

        foreach ([
            ['GET', '/api/v1/wallet'],
            ['POST', '/api/v1/wallet/withdraw/kpay'],
            ['POST', '/api/v1/wallet/withdraw/stripe'],
            ['PUT', '/api/v1/auth/profile'],
            ['POST', '/api/v1/auth/delete-account'],
            ['POST', '/api/v1/auth/request-phone-change'],
            ['POST', '/api/v1/packages/subscribe'],
            ['POST', '/api/v1/web-login/scan'],
            ['GET', '/api/v1/web-login/devices'],
            ['POST', '/api/v1/vendor/boosts/1/cancel'],
        ] as [$method, $url]) {
            $this->asBrowser($token)->json($method, $url)
                ->assertForbidden()
                ->assertJsonPath('code', 'web_scope_denied');
        }
    }

    /**
     * Le garde-fou principal : aucune route authentifiée n'est ouverte au jeton web
     * sans figurer dans la liste blanche, y compris les routes ajoutées plus tard.
     */
    public function test_toute_route_authentifiee_hors_liste_blanche_est_refusee(): void
    {
        $vendor = $this->vendor();
        $token = $vendor->createToken('vendor-web', ['vendor-web'], now()->addHour())->plainTextToken;
        $allowed = config('web_vendor.routes');
        $checked = 0;

        foreach (Route::getRoutes() as $route) {
            if (! in_array('auth:sanctum', $route->gatherMiddleware(), true)) {
                continue;
            }
            $method = collect($route->methods())->first(fn ($m) => $m !== 'HEAD');
            if (in_array($method.' '.$route->uri(), $allowed, true)) {
                continue;
            }

            $url = $this->urlFor($route);
            $this->asBrowser($token)->json($method, $url)
                ->assertForbidden()
                ->assertJsonPath('code', 'web_scope_denied');
            $checked++;
        }

        $this->assertGreaterThan(150, $checked);
    }

    /** Adresse qui atteint vraiment la route : ses contraintes de paramètres sont respectées. */
    private function urlFor(\Illuminate\Routing\Route $route): string
    {
        $uri = preg_replace_callback('/\{([^}?]+)\??\}/', function ($match) use ($route) {
            $constraint = $route->wheres[$match[1]] ?? null;
            if ($constraint === null) {
                return '1';
            }
            $candidates = ['1', '2026-01-01', '00000000-0000-4000-8000-000000000000', 'a', explode('|', trim($constraint, '()'))[0]];
            foreach ($candidates as $candidate) {
                if (preg_match('#^'.$constraint.'$#', $candidate)) {
                    return $candidate;
                }
            }
            $this->fail("Aucune valeur pour {$match[1]} de {$route->uri()}");
        }, $route->uri());

        return '/'.$uri;
    }

    public function test_la_liste_blanche_ne_contient_que_de_vraies_routes(): void
    {
        $known = collect(Route::getRoutes())
            ->flatMap(fn ($route) => collect($route->methods())->map(fn ($m) => $m.' '.$route->uri()))
            ->all();

        foreach (config('web_vendor.routes') as $entry) {
            $this->assertContains($entry, $known, $entry);
        }
    }

    public function test_le_jeton_web_ouvre_les_ecrans_vendeur(): void
    {
        $token = $this->signIn($this->vendor());

        foreach (['/api/v1/vendor/dashboard', '/api/v1/vendor/products', '/api/v1/vendor/orders', '/api/v1/vendor/shop', '/api/v1/vendor/statistics'] as $url) {
            $response = $this->asBrowser($token)->getJson($url);
            $this->assertNotSame('web_scope_denied', $response->json('code'), $url);
            $this->assertNotContains($response->status(), [401, 403], $url);
        }
    }

    public function test_le_jeton_de_l_application_n_est_pas_limite(): void
    {
        $response = $this->asPhone($this->vendor())->getJson('/api/v1/wallet');

        $this->assertNotSame('web_scope_denied', $response->json('code'));
    }

    public function test_un_jeton_web_expire_ne_passe_plus(): void
    {
        $token = $this->signIn($this->vendor());

        $this->travel(13)->hours();

        $this->asBrowser($token)->getJson('/api/v1/vendor/dashboard')->assertUnauthorized();
    }

    public function test_couper_l_interrupteur_ferme_les_sessions_ouvertes(): void
    {
        $token = $this->signIn($this->vendor());

        Setting::set('web_vendor_login_enabled', false, 'boolean', 'links');

        $this->asBrowser($token)->getJson('/api/v1/vendor/dashboard')
            ->assertForbidden()
            ->assertJsonPath('code', 'web_scope_denied');
        $this->withoutToken()->postJson('/api/v1/web-login/sessions')->assertNotFound();
    }

    public function test_le_vendeur_voit_et_revoque_ses_navigateurs(): void
    {
        $vendor = $this->vendor();
        $token = $this->signIn($vendor);

        $devices = $this->asPhone($vendor)->getJson('/api/v1/web-login/devices')->assertOk()->json('sessions');
        $this->assertCount(1, $devices);
        $this->assertSame('Chrome', $devices[0]['browser']);

        $this->asPhone($this->vendor())->deleteJson('/api/v1/web-login/devices/'.$devices[0]['id'])->assertNotFound();
        $this->asPhone($vendor)->deleteJson('/api/v1/web-login/devices/'.$devices[0]['id'])->assertOk();

        $this->asBrowser($token)->getJson('/api/v1/vendor/dashboard')->assertUnauthorized();
        $this->assertSame([], $this->asPhone($vendor)->getJson('/api/v1/web-login/devices')->json('sessions'));
    }

    public function test_la_revocation_ne_touche_pas_au_jeton_de_l_application(): void
    {
        $vendor = $this->vendor();
        $phone = $vendor->createToken('mobile-app')->accessToken;

        $this->withToken($vendor->createToken('mobile-app')->plainTextToken)
            ->deleteJson('/api/v1/web-login/devices/'.$phone->id)
            ->assertNotFound();

        $this->assertNotNull(PersonalAccessToken::find($phone->id));
    }

    public function test_la_page_vendor_suit_l_interrupteur_et_interdit_l_inclusion(): void
    {
        $dir = public_path('vendor-web');
        $created = ! is_dir($dir);
        if ($created) {
            mkdir($dir);
        }
        $index = $dir.'/index.html';
        $hadIndex = is_file($index);
        if (! $hadIndex) {
            file_put_contents($index, '<html>espace vendeur</html>');
        }

        try {
            $this->get('/vendor')->assertOk()->assertHeader('X-Frame-Options', 'DENY');
            $this->get('/vendor/')->assertOk();

            Setting::set('web_vendor_login_enabled', false, 'boolean', 'links');
            $this->get('/vendor')->assertNotFound();
        } finally {
            if (! $hadIndex) {
                unlink($index);
            }
            if ($created) {
                rmdir($dir);
            }
        }
    }

    public function test_le_script_tailwind_de_l_admin_a_bien_quitte_public_vendor(): void
    {
        $this->assertDirectoryDoesNotExist(public_path('vendor'));
        $this->assertFileExists(public_path('tailwindcss/tailwindcss-3.4.17.js'));

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('admin.settings.index'))
            ->assertOk()
            ->assertSee('tailwindcss/tailwindcss-3.4.17.js', false)
            ->assertSee('Espace vendeur web');
    }
}
