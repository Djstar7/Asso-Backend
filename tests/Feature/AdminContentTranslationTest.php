<?php

namespace Tests\Feature;

use App\Models\Banner;
use App\Models\DelivererCompany;
use App\Models\ImportCountry;
use App\Models\LegalPage;
use App\Models\Package;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminContentTranslationTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    public function test_package_is_saved_in_french_and_english_and_form_shows_both(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get('/admin/packages/create')->assertOk()->assertSee('translations[en][name]', false);

        $this->actingAs($admin)->post('/admin/packages', [
            'type' => 'certification', 'name' => 'Certification Or', 'description' => 'Pour les meilleurs',
            'price' => 5000, 'duration_days' => 30, 'benefits' => ['Badge', ''], 'is_active' => 1,
            'translations' => ['en' => ['name' => 'Gold certification', 'description' => '', 'benefits' => ['Badge', '']]],
        ])->assertRedirect();

        $package = Package::firstOrFail();
        $this->assertSame('Certification Or', $package->name);
        $this->assertSame('Gold certification', $package->getTranslation('name', 'en'));
        $this->assertNull($package->getTranslation('description', 'en'));
        $this->assertSame(['Badge'], $package->getTranslation('benefits', 'en'));

        $this->actingAs($admin)->get("/admin/packages/{$package->id}/edit")
            ->assertOk()
            ->assertSee('Gold certification');

        // Passé en stockage : les avantages anglais n'ont plus lieu d'être.
        $this->actingAs($admin)->put("/admin/packages/{$package->id}", [
            'type' => 'storage', 'name' => 'Stockage', 'price' => 1000, 'duration_days' => 30, 'storage_size_mb' => 300,
            'translations' => ['en' => ['name' => 'Storage']],
        ])->assertRedirect();
        $this->assertNull($package->fresh()->getTranslation('benefits', 'en'));
        $this->assertSame('Storage', $package->fresh()->getTranslation('name', 'en'));
    }

    public function test_legal_page_english_content_is_saved_from_the_editor(): void
    {
        $admin = $this->admin();
        $page = LegalPage::create(['slug' => 'cgu', 'title' => 'CGU', 'content' => '<p>Règles</p>', 'is_active' => true, 'order' => 1]);

        $this->actingAs($admin)->get("/admin/legal-pages/{$page->id}/edit")->assertOk()->assertSee('editor-en', false);

        $this->actingAs($admin)->put("/admin/legal-pages/{$page->id}", [
            'title' => 'CGU', 'slug' => 'cgu', 'content' => '<p>Règles</p>', 'is_active' => 1,
            'translations' => ['en' => ['title' => 'Terms', 'content' => '<p>Rules</p>']],
        ])->assertRedirect();

        $this->assertSame('<p>Rules</p>', $page->fresh()->getTranslation('content', 'en'));
        $this->assertSame('<p>Règles</p>', $page->fresh()->content);
    }

    public function test_public_settings_and_banners_accept_english_versions(): void
    {
        $admin = $this->admin();
        Setting::set('app_slogan', 'Votre plateforme', 'string', 'general');

        $this->actingAs($admin)->get('/admin/settings')->assertOk()->assertSee('translations[en][app_slogan]', false);

        $this->actingAs($admin)->put('/admin/settings/update', [
            'app_name' => 'ASSO', 'app_slogan' => 'Votre plateforme', 'contact_email' => 'contact@asso.test',
            'translations' => ['en' => ['app_slogan' => 'Your platform']],
        ])->assertRedirect();

        $this->assertSame('Your platform', Setting::where('key', 'app_slogan')->first()->getTranslation('value', 'en'));
        $this->assertNull(Setting::where('key', 'translations')->first());

        $this->actingAs($admin)->get('/admin/banners/create')->assertOk()->assertSee('translations[en][title]', false);
    }

    public function test_delivery_partner_route_and_import_note_keep_their_english_text(): void
    {
        $admin = $this->admin();
        $partner = DelivererCompany::create(['name' => 'SOLEX', 'service_type' => 'intercity', 'service_mode' => 'agency_to_agency', 'is_active' => true]);

        $this->actingAs($admin)->put("/admin/delivery-partners/{$partner->id}", [
            'name' => 'SOLEX', 'service_type' => 'intercity', 'service_mode' => 'agency_to_agency',
            'conditions' => 'Retrait en agence', 'translations' => ['en' => ['conditions' => 'Agency pickup']],
        ])->assertRedirect();
        $this->assertSame('Agency pickup', $partner->fresh()->getTranslation('conditions', 'en'));

        $this->actingAs($admin)->get("/admin/delivery-partners/{$partner->id}")->assertOk()->assertSee('Agency pickup');

        $this->actingAs($admin)->post("/admin/delivery-partners/{$partner->id}/routes", [
            'origin_country' => 'CM', 'origin_city' => 'Douala', 'destination_country' => 'CM', 'destination_city' => 'Yaoundé',
            'lead_time' => '2 jours', 'ranges' => [['min' => 0, 'max' => 2, 'price' => 2500]],
            'translations' => ['en' => ['lead_time' => '2 days']],
        ])->assertRedirect();
        $this->assertSame('2 days', $partner->deliveryRoutes()->first()->getTranslation('lead_time', 'en'));

        $country = ImportCountry::create(['code' => 'CN', 'name' => 'Chine', 'sort_order' => 1]);
        $this->actingAs($admin)->postJson("/admin/import-countries/{$country->id}/shipping-options", [
            'mode' => 'air', 'rate_type' => 'per_kg', 'rate_amount' => 5000, 'lead_time_days' => 9,
            'expedition_note' => 'Départ hebdomadaire', 'translations' => ['en' => ['expedition_note' => 'Weekly departure']],
        ])->assertOk()->assertJsonPath('shipping_option.translations.en.expedition_note', 'Weekly departure');
    }
}
