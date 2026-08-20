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

    public function test_submit_creates_account_and_sets_pending(): void
    {
        $this->mockStripe(function ($mock) {
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('createCustomAccountWithBank')->once()->andReturn([
                'id' => 'acct_test_123',
                'external_last4' => '3000',
                'bank_country' => 'FR',
                'account_holder_name' => 'Jean Dupont',
            ]);
        });

        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/stripe/connect/submit', [
                'country' => 'FR',
                'iban' => 'FR1420041010050500013M02606',
                'account_holder_name' => 'Jean Dupont',
            ])
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
            ->postJson('/api/v1/stripe/connect/submit', [
                'country' => 'FR',
                'iban' => 'PAS-UN-IBAN',
                'account_holder_name' => 'Jean Dupont',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('iban');
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
            ->postJson('/api/v1/stripe/connect/submit', [
                'country' => 'FR',
                'iban' => 'FR1420041010050500013M02606',
                'account_holder_name' => 'Jean Dupont',
            ])
            ->assertStatus(422);
    }

    public function test_submit_returns_503_when_stripe_not_configured(): void
    {
        $this->mockStripe(function ($mock) {
            $mock->shouldReceive('isConfigured')->andReturn(false);
        });

        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/stripe/connect/submit', [
                'country' => 'FR',
                'iban' => 'FR1420041010050500013M02606',
                'account_holder_name' => 'Jean Dupont',
            ])
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
        });

        $user = User::factory()->create();
        $user->forceFill([
            'stripe_account_id' => 'acct_existing',
            'stripe_account_status' => 'rejected',
            'stripe_rejection_reason' => 'IBAN illisible',
        ])->saveQuietly();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/stripe/connect/submit', [
                'country' => 'FR',
                'iban' => 'FR7630006000011234567890189',
                'account_holder_name' => 'Jean Dupont',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.iban_last4', '9999');

        $user->refresh();
        $this->assertSame('pending', $user->stripe_account_status);
        $this->assertNull($user->stripe_rejection_reason);
    }

    public function test_status_endpoint_reflects_state(): void
    {
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
            ->assertJsonPath('data.iban_last4', '3000');
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
