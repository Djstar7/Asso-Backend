<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\FirebaseMessagingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Validation admin des comptes Stripe Connect (Phase 3) + notifications (Phase 4).
 * FirebaseMessagingService est mocké (pas d'appel réseau).
 */
class StripeConnectAdminTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $u = User::factory()->create();
        $u->forceFill(['role' => 'admin'])->saveQuietly();
        return $u;
    }

    private function pendingSeller(): User
    {
        $u = User::factory()->create();
        $u->forceFill([
            'stripe_account_id' => 'acct_seller',
            'stripe_account_status' => 'pending',
            'stripe_external_last4' => '3000',
            'stripe_submitted_at' => now(),
        ])->saveQuietly();
        return $u;
    }

    private function expectNotification(string $type): void
    {
        $this->mock(FirebaseMessagingService::class, function ($mock) use ($type) {
            $mock->shouldReceive('sendToUser')
                ->once()
                ->withArgs(fn($user, $title, $body, $data = []) => ($data['type'] ?? null) === $type)
                ->andReturn([]);
        });
    }

    public function test_admin_approves_pending_account_and_notifies(): void
    {
        $this->expectNotification('stripe_account_approved');
        $admin = $this->admin();
        $seller = $this->pendingSeller();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/stripe/accounts/{$seller->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $seller->refresh();
        $this->assertSame('approved', $seller->stripe_account_status);
        $this->assertNotNull($seller->stripe_verified_at);
        $this->assertNull($seller->stripe_rejection_reason);
    }

    public function test_admin_rejects_with_reason_and_notifies(): void
    {
        $this->expectNotification('stripe_account_rejected');
        $admin = $this->admin();
        $seller = $this->pendingSeller();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/stripe/accounts/{$seller->id}/reject", [
                'reason' => 'IBAN ne correspond pas au titulaire',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected');

        $seller->refresh();
        $this->assertSame('rejected', $seller->stripe_account_status);
        $this->assertSame('IBAN ne correspond pas au titulaire', $seller->stripe_rejection_reason);
        $this->assertNull($seller->stripe_verified_at);
    }

    public function test_reject_requires_reason(): void
    {
        $admin = $this->admin();
        $seller = $this->pendingSeller();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/stripe/accounts/{$seller->id}/reject", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');
    }

    public function test_non_admin_is_forbidden(): void
    {
        $seller = $this->pendingSeller();
        $intruder = User::factory()->create(); // role non-admin

        $this->actingAs($intruder, 'sanctum')
            ->postJson("/api/v1/admin/stripe/accounts/{$seller->id}/approve")
            ->assertStatus(403);

        $seller->refresh();
        $this->assertSame('pending', $seller->stripe_account_status);
    }

    public function test_cannot_approve_already_processed_account(): void
    {
        $admin = $this->admin();
        $seller = User::factory()->create();
        $seller->forceFill([
            'stripe_account_id' => 'acct_seller',
            'stripe_account_status' => 'approved',
        ])->saveQuietly();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/stripe/accounts/{$seller->id}/approve")
            ->assertStatus(422);
    }

    public function test_index_lists_pending_accounts_with_counts(): void
    {
        $admin = $this->admin();
        $this->pendingSeller();

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/admin/stripe/accounts')
            ->assertOk()
            ->assertJsonPath('counts.pending', 1)
            ->assertJsonPath('accounts.0.status', 'pending');
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
