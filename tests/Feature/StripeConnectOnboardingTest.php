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

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
