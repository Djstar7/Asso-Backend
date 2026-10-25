<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminSettingsTabsTest extends TestCase
{
    use RefreshDatabase;

    public function test_settings_save_returns_to_system_tab_without_saving_ui_metadata(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->put(route('admin.settings.update'), [
                '_active_tab' => 'system',
                'timezone' => 'Africa/Douala', 'default_language' => 'fr',
                'currency' => 'XAF', 'currency_symbol' => 'FCFA',
                'min_deposit_amount' => 100, 'min_withdrawal_amount' => 500,
            ])
            ->assertRedirect(route('admin.settings.index', ['tab' => 'system']))
            ->assertSessionHas('success');
        $this->assertSame('Africa/Douala', Setting::get('timezone'));
        $this->assertDatabaseMissing('settings', ['key' => '_active_tab']);
    }

    public function test_payment_and_service_forms_preserve_the_selected_tab(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->put(route('admin.settings.payments.update'), ['_active_tab' => 'direct', '_form' => 'stripe'])
            ->assertRedirect(route('admin.settings.payments', ['tab' => 'direct']));
        $this->put(route('admin.settings.services.update'), ['_active_tab' => 'whatsapp', 'whatsapp_template_language' => 'fr'])
            ->assertRedirect(route('admin.settings.services', ['tab' => 'whatsapp']));
        $this->put(route('admin.settings.services.update'), ['_active_tab' => 'https://example.com'])
            ->assertRedirect(route('admin.settings.services'));
    }

    public function test_all_settings_pages_load_tab_persistence_before_alpine(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        foreach (['index', 'payments', 'services'] as $page) {
            $html = $this->get(route('admin.settings.'.$page))->assertOk()->getContent();
            $this->assertLessThan(strpos($html, 'alpinejs@'), strpos($html, 'js/admin-tabs.js'));
            $this->assertStringContainsString('adminTabState(', $html);
            $this->assertStringContainsString('name="_active_tab"', $html);
        }
    }
}
