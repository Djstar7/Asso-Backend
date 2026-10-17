<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Services\CommissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** Paramètres > Mon compte : nom, e-mail et mot de passe de l'admin connecté. */
class AdminAccountSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create([
            'role' => 'admin', 'roles' => ['admin'], 'email' => 'admin@asso.com', 'password' => Hash::make('ancien-mdp'),
        ]);
    }

    public function test_email_change_requires_the_current_password_and_keeps_the_platform_account(): void
    {
        $admin = $this->admin();
        $this->assertTrue(CommissionService::platformAccount()->is($admin));

        $this->actingAs($admin)->from('/admin/settings')
            ->put('/admin/settings/account', ['first_name' => 'Steve', 'email' => 'direction@asso.cm'])
            ->assertSessionHasErrorsIn('account', 'current_password');

        $this->actingAs($admin)
            ->put('/admin/settings/account', [
                'first_name' => 'Steve', 'last_name' => 'B', 'email' => 'direction@asso.cm', 'current_password' => 'ancien-mdp',
            ])->assertSessionHas('success');

        $this->assertSame('direction@asso.cm', $admin->fresh()->email);
        // Les commissions ASSO continuent d'arriver sur ce compte.
        $this->assertSame('direction@asso.cm', Setting::get('platform_account_email'));
        $this->assertTrue(CommissionService::platformAccount()->is($admin));
    }

    public function test_password_change(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->put('/admin/settings/account/password', [
            'current_password' => 'faux', 'password' => 'nouveau-mdp-1', 'password_confirmation' => 'nouveau-mdp-1',
        ])->assertSessionHasErrorsIn('password', 'current_password');

        $this->actingAs($admin)->put('/admin/settings/account/password', [
            'current_password' => 'ancien-mdp', 'password' => 'nouveau-mdp-1', 'password_confirmation' => 'nouveau-mdp-1',
        ])->assertSessionHas('success');

        $this->assertTrue(Hash::check('nouveau-mdp-1', $admin->fresh()->password));
    }

    public function test_settings_page_shows_the_account_tab(): void
    {
        $this->actingAs($this->admin())->get('/admin/settings?tab=account')->assertOk()->assertSee('Mon compte');
    }
}
