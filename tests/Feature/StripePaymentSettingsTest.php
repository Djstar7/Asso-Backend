<?php

namespace Tests\Feature;

use App\Models\ServiceConfiguration;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Panneau admin « Paiements » : réglages du virement bancaire vendeur.
 *
 * Ces valeurs pilotent la chaîne de virement (minimum, marge de change, profil
 * transmis à Stripe, secret des événements Connect) : sans elles dans l'interface,
 * seule la ligne de commande permettait de les changer.
 */
class StripePaymentSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->forceFill(['role' => 'admin'])->saveQuietly();
        return $user;
    }

    public function test_payments_page_loads(): void
    {
        $this->actingAs($this->admin())
            ->get(route('admin.settings.payments'))
            ->assertOk()
            ->assertSee('Virements bancaires vendeurs', false)
            ->assertSee('stripe_fx_buffer_percent', false)
            ->assertSee('stripe_webhook_secret_connect', false);
    }

    public function test_payout_settings_are_saved(): void
    {
        $this->actingAs($this->admin())
            ->put(route('admin.settings.payments.update'), [
                'min_stripe_withdrawal_amount' => 10,
                'stripe_fx_buffer_percent' => 4.5,
                'stripe_business_url' => 'https://mon-asso.com',
                'stripe_business_mcc' => '5732',
            ])
            ->assertRedirect(route('admin.settings.payments'));

        $this->assertSame('10', (string) Setting::get('min_stripe_withdrawal_amount'));
        $this->assertSame('4.5', (string) Setting::get('stripe_fx_buffer_percent'));
        $this->assertSame('https://mon-asso.com', Setting::get('stripe_business_url'));
        $this->assertSame('5732', (string) Setting::get('stripe_business_mcc'));
    }

    /** Le secret Connect rejoint les clés Stripe, pas la table des réglages. */
    public function test_connect_webhook_secret_goes_to_service_configuration(): void
    {
        $this->actingAs($this->admin())
            ->put(route('admin.settings.payments.update'), [
                // Le toggle Stripe conditionne la LECTURE des clés (is_active) :
                // sans lui, la configuration enregistrée serait invisible du service.
                'stripe_enabled' => 1,
                'stripe_webhook_secret_connect' => 'whsec_connect_test',
            ])
            ->assertRedirect(route('admin.settings.payments'));

        $config = ServiceConfiguration::getConfig(ServiceConfiguration::SERVICE_STRIPE) ?? [];
        $this->assertSame('whsec_connect_test', $config['webhook_secret_connect'] ?? null);
        $this->assertNull(Setting::get('stripe_webhook_secret_connect'));
    }

    /**
     * Piège de configuration à connaître : décocher le toggle Stripe rend TOUTE la
     * configuration invisible du service (is_active=false), donc les virements IBAN
     * s'arrêtent aussi — pas seulement l'encaissement carte.
     */
    public function test_disabling_stripe_hides_the_whole_configuration(): void
    {
        $this->actingAs($this->admin())
            ->put(route('admin.settings.payments.update'), [
                'stripe_enabled' => 1,
                'stripe_secret_key' => 'sk_test_abc',
            ]);

        $this->assertSame('sk_test_abc', ServiceConfiguration::getConfig(ServiceConfiguration::SERVICE_STRIPE)['secret_key'] ?? null);

        $this->actingAs($this->admin())
            ->put(route('admin.settings.payments.update'), ['stripe_enabled' => 0]);

        $this->assertNull(ServiceConfiguration::getConfig(ServiceConfiguration::SERVICE_STRIPE));
    }

    /** Un secret laissé vide ne doit jamais écraser celui déjà enregistré. */
    public function test_empty_secret_keeps_previous_value(): void
    {
        ServiceConfiguration::setConfig(ServiceConfiguration::SERVICE_STRIPE, [
            'mode' => 'test',
            'secret_key' => 'sk_test_existing',
            'webhook_secret_connect' => 'whsec_existing',
        ], true, 'Stripe');

        $this->actingAs($this->admin())
            ->put(route('admin.settings.payments.update'), [
                'stripe_enabled' => 1,
                'stripe_webhook_secret_connect' => '',
                'stripe_secret_key' => '',
            ])
            ->assertRedirect(route('admin.settings.payments'));

        $config = ServiceConfiguration::getConfig(ServiceConfiguration::SERVICE_STRIPE) ?? [];
        $this->assertSame('whsec_existing', $config['webhook_secret_connect'] ?? null);
        $this->assertSame('sk_test_existing', $config['secret_key'] ?? null);
    }
}
