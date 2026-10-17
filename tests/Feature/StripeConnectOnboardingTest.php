<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\StripeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Onboarding Stripe Connect vendeur (Phase 2).
 *
 * StripeService est MOCKÉ : ces tests n'ont pas besoin de clés Stripe réelles —
 * ils vérifient la logique du contrôleur (statut pending, validations, garde-fous).
 */
class StripeConnectOnboardingTest extends TestCase
{
    use RefreshDatabase;

    private function mockStripe(callable $expectations): void
    {
        $this->mock(StripeService::class, function ($mock) use ($expectations) {
            $expectations($mock);
        });
    }

    /**
     * Formulaire vendeur complet. Les champs KYC (naissance, téléphone, adresse) sont
     * OBLIGATOIRES : sans eux Stripe laisse le compte en `requirements.past_due` et
     * aucun virement ne peut aboutir.
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'country' => 'FR',
            'iban' => 'FR1420041010050500013M02606',
            'account_holder_name' => 'Jean Dupont',
            'birth_date' => '1990-05-17',
            'phone' => '+33612345678',
            'address_line1' => '12 rue de la Paix',
            'address_city' => 'Paris',
            'address_postal_code' => '75002',
        ], $overrides);
    }

    public function test_submit_creates_account_and_sets_pending(): void
    {
        $this->mockStripe(function ($mock) {
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('createCustomAccountWithBank')->once()
                ->withArgs(function (array $data) {
                    // Le KYC saisi par le vendeur doit bien être transmis à Stripe.
                    return $data['birth_date'] === '1990-05-17'
                        && $data['phone'] === '+33612345678'
                        && $data['address_line1'] === '12 rue de la Paix'
                        && $data['address_city'] === 'Paris'
                        && $data['address_postal_code'] === '75002';
                })
                ->andReturn([
                    'id' => 'acct_test_123',
                    'external_last4' => '3000',
                    'bank_country' => 'FR',
                    'account_holder_name' => 'Jean Dupont',
                ]);
        });

        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/stripe/connect/submit', $this->payload())
            ->assertOk()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.iban_last4', '3000');

        $user->refresh();
        $this->assertSame('acct_test_123', $user->stripe_account_id);
        $this->assertSame('pending', $user->stripe_account_status);
        $this->assertNotNull($user->stripe_submitted_at);
    }

    public function test_submit_rejects_invalid_iban(): void
    {
        $this->mockStripe(function ($mock) {
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('createCustomAccountWithBank')->never();
        });

        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/stripe/connect/submit', $this->payload(['iban' => 'PAS-UN-IBAN']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('iban');
    }

    /** Sans KYC, le compte serait créé mais inutilisable : on refuse en amont. */
    public function test_submit_requires_kyc_fields(): void
    {
        $this->mockStripe(function ($mock) {
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('createCustomAccountWithBank')->never();
        });

        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/stripe/connect/submit', [
                'country' => 'FR',
                'iban' => 'FR1420041010050500013M02606',
                'account_holder_name' => 'Jean Dupont',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'birth_date', 'phone', 'address_line1', 'address_city', 'address_postal_code',
            ]);
    }

    /** Stripe refuse les mineurs : autant le dire tout de suite au vendeur. */
    public function test_submit_rejects_minor(): void
    {
        $this->mockStripe(function ($mock) {
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('createCustomAccountWithBank')->never();
        });

        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/stripe/connect/submit', $this->payload([
                'birth_date' => now()->subYears(15)->toDateString(),
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('birth_date');
    }

    public function test_submit_blocked_when_already_approved(): void
    {
        $this->mockStripe(function ($mock) {
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('accountState')->andReturn(['missing' => false]);
            $mock->shouldReceive('createCustomAccountWithBank')->never();
            $mock->shouldReceive('replaceExternalAccount')->never();
        });

        $user = User::factory()->create();
        $user->forceFill([
            'stripe_account_id' => 'acct_existing',
            'stripe_account_status' => 'approved',
        ])->saveQuietly();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/stripe/connect/submit', $this->payload())
            ->assertStatus(422);
    }

    public function test_approved_iban_can_be_unlinked_then_replaced(): void
    {
        $user = User::factory()->create();
        $user->forceFill([
            'stripe_account_id' => 'acct_existing',
            'stripe_account_status' => 'approved',
            'stripe_external_last4' => '2606',
            'stripe_bank_country' => 'FR',
            'stripe_account_holder_name' => 'Jean Dupont',
        ])->saveQuietly();

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/stripe/connect')
            ->assertOk()
            ->assertJsonPath('data.status', null)
            ->assertJsonPath('data.iban_last4', null)
            // Le compte Connect reste : un nouvel IBAN le remplacera.
            ->assertJsonPath('data.has_account', true);

        $this->assertFalse(\App\Services\StripeWithdrawalService::isReady($user->fresh()));
    }

    public function test_unlink_refused_while_a_transfer_is_in_progress(): void
    {
        $user = User::factory()->create();
        $user->forceFill([
            'stripe_account_id' => 'acct_existing',
            'stripe_account_status' => 'approved',
            'stripe_external_last4' => '2606',
        ])->saveQuietly();
        \App\Models\PlatformWithdrawal::create([
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
            'status' => 'processing',
            'transaction_reference' => 'TXN-' . uniqid(),
        ]);

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/stripe/connect')
            ->assertStatus(422);
        $this->assertSame('approved', $user->fresh()->stripe_account_status);
    }

    public function test_submit_returns_503_when_stripe_not_configured(): void
    {
        $this->mockStripe(function ($mock) {
            $mock->shouldReceive('isConfigured')->andReturn(false);
        });

        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/stripe/connect/submit', $this->payload())
            ->assertStatus(503);
    }

    public function test_resubmit_after_rejection_replaces_iban_and_resets_pending(): void
    {
        $this->mockStripe(function ($mock) {
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('accountState')->andReturn(['missing' => false]);
            $mock->shouldReceive('createCustomAccountWithBank')->never();
            $mock->shouldReceive('replaceExternalAccount')->once()->andReturn([
                'external_last4' => '9999',
                'bank_country' => 'FR',
            ]);
            // Le KYC est rafraîchi à la resoumission : c'est souvent lui qui bloquait.
            $mock->shouldReceive('updateAccountKyc')->once()
                ->withArgs(fn (string $id, array $data) => $id === 'acct_existing'
                    && $data['birth_date'] === '1990-05-17');
        });

        $user = User::factory()->create();
        $user->forceFill([
            'stripe_account_id' => 'acct_existing',
            'stripe_account_status' => 'rejected',
            'stripe_rejection_reason' => 'IBAN illisible',
        ])->saveQuietly();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/stripe/connect/submit', $this->payload([
                'iban' => 'FR7630006000011234567890189',
            ]))
            ->assertOk()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.iban_last4', '9999');

        $user->refresh();
        $this->assertSame('pending', $user->stripe_account_status);
        $this->assertNull($user->stripe_rejection_reason);
    }

    public function test_status_endpoint_reflects_state(): void
    {
        $this->mockStripe(function ($mock) {
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('accountState')->andReturn([
                'exists' => true,
                'transfers' => 'pending',
                'payouts_enabled' => false,
                'charges_enabled' => false,
                'requirements_due' => [],
                'disabled_reason' => null,
                'ready' => false,
                'error' => null,
            ]);
        });

        $user = User::factory()->create();
        $user->forceFill([
            'stripe_account_id' => 'acct_x',
            'stripe_account_status' => 'pending',
            'stripe_external_last4' => '3000',
        ])->saveQuietly();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/stripe/connect/status')
            ->assertOk()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.has_account', true)
            ->assertJsonPath('data.iban_last4', '3000')
            // L'état réel Stripe est remonté au vendeur (vérification en cours).
            ->assertJsonPath('data.stripe.ready', false)
            ->assertJsonPath('data.stripe.verification', 'Vérification en cours chez Stripe (quelques minutes).');
    }

    /**
     * Compte Connect créé en mode test puis passage aux clés live : il est
     * introuvable pour la clé actuelle. Le renvoi de l'IBAN doit créer un NOUVEAU
     * compte (même si le statut ASSO était « approved »), au lieu d'échouer
     * indéfiniment sur « does not have access to account ».
     */
    public function test_resubmit_recreates_account_missing_for_current_key(): void
    {
        $this->mockStripe(function ($mock) {
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('mode')->andReturn('live');
            $mock->shouldReceive('accountState')->with('acct_from_test_mode')->andReturn(['missing' => true]);
            $mock->shouldReceive('replaceExternalAccount')->never();
            $mock->shouldReceive('createCustomAccountWithBank')->once()->andReturn([
                'id' => 'acct_live_new',
                'external_last4' => '0189',
                'bank_country' => 'FR',
                'account_holder_name' => 'Jean Dupont',
            ]);
        });

        $user = User::factory()->create();
        $user->forceFill([
            'stripe_account_id' => 'acct_from_test_mode',
            'stripe_account_status' => 'approved',
        ])->saveQuietly();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/stripe/connect/submit', $this->payload())
            ->assertOk()
            ->assertJsonPath('data.status', 'pending');

        $user->refresh();
        $this->assertSame('acct_live_new', $user->stripe_account_id);
        $this->assertSame('pending', $user->stripe_account_status);
    }

    /**
     * Profil plateforme Connect non rempli en live : ce n'est pas la faute du
     * vendeur, il ne doit pas lire « informations invalides ».
     */
    public function test_platform_config_error_is_not_blamed_on_vendor(): void
    {
        $this->mockStripe(function ($mock) {
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('mode')->andReturn('live');
            $mock->shouldReceive('createCustomAccountWithBank')->once()->andThrow(
                new \Stripe\Exception\InvalidRequestException(
                    'You must complete your platform profile to use Connect and create live connected accounts.'
                )
            );
        });

        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/stripe/connect/submit', $this->payload())
            ->assertStatus(422)
            ->assertJsonPath('message', __('payments.stripe_connect.error_unavailable'));
    }

    public function test_status_reopens_form_when_account_missing_for_current_key(): void
    {
        $this->mockStripe(function ($mock) {
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('accountState')->andReturn([
                'exists' => false,
                'transfers' => 'unknown',
                'payouts_enabled' => false,
                'charges_enabled' => false,
                'requirements_due' => [],
                'disabled_reason' => null,
                'ready' => false,
                'error' => "does not have access to account 'acct_x'",
                'missing' => true,
            ]);
        });

        $user = User::factory()->create();
        $user->forceFill([
            'stripe_account_id' => 'acct_x',
            'stripe_account_status' => 'approved',
        ])->saveQuietly();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/stripe/connect/status')
            ->assertOk()
            ->assertJsonPath('data.has_account', false)
            ->assertJsonPath('data.status', null);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
