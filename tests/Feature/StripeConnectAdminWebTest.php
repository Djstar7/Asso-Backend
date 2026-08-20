<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\FirebaseMessagingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Panneau admin WEB (Blade) de validation des comptes Stripe Connect.
 * Vérifie le câblage des routes web + contrôleur (session auth, redirections).
 */
class StripeConnectAdminWebTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(FirebaseMessagingService::class, function ($mock) {
            $mock->shouldReceive('sendToUser')->andReturn([]);
        });
    }

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

    public function test_admin_web_list_page_loads(): void
    {
        $this->pendingSeller();

        $this->actingAs($this->admin())
            ->get(route('admin.stripe.accounts.index'))
            ->assertOk()
            ->assertSee('Comptes de virement');
    }

    public function test_admin_web_approve_redirects_and_updates(): void
    {
        $seller = $this->pendingSeller();

        $this->actingAs($this->admin())
            ->post(route('admin.stripe.accounts.approve', $seller->id))
            ->assertRedirect(route('admin.stripe.accounts.index'))
            ->assertSessionHas('success');

        $this->assertSame('approved', $seller->fresh()->stripe_account_status);
    }

    public function test_admin_web_reject_requires_reason(): void
    {
        $seller = $this->pendingSeller();

        $this->actingAs($this->admin())
            ->post(route('admin.stripe.accounts.reject', $seller->id), [])
            ->assertSessionHasErrors('reason');

        $this->assertSame('pending', $seller->fresh()->stripe_account_status);
    }

    public function test_admin_web_reject_with_reason_updates(): void
    {
        $seller = $this->pendingSeller();

        $this->actingAs($this->admin())
            ->post(route('admin.stripe.accounts.reject', $seller->id), ['reason' => 'IBAN erroné'])
            ->assertRedirect(route('admin.stripe.accounts.index'))
            ->assertSessionHas('success');

        $seller->refresh();
        $this->assertSame('rejected', $seller->stripe_account_status);
        $this->assertSame('IBAN erroné', $seller->stripe_rejection_reason);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
