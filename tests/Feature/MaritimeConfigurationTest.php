<?php

namespace Tests\Feature;

use App\Models\Banner;
use App\Models\DelivererCompany;
use App\Models\MaritimeDeparture;
use App\Models\MaritimeHub;
use App\Models\PartnerFreightRate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

class MaritimeConfigurationTest extends TestCase
{
    use RefreshDatabase;

    public function test_banner_api_returns_the_saved_image_and_updated_description(): void
    {
        $banner = Banner::create(['title' => 'Marché', 'description' => 'Nouvelle campagne', 'image_path' => 'banners/new.webp', 'position' => 1, 'is_active' => true]);
        Banner::create(['image_path' => 'banners/hidden.webp', 'position' => 0, 'is_active' => false]);
        $this->getJson('/api/v1/banners')->assertOk()->assertJsonCount(1, 'banners')
            ->assertJsonPath('banners.0.image', $banner->image_url)
            ->assertJsonPath('banners.0.subtitle', 'Nouvelle campagne');
        $banner->update(['image_path' => 'banners/replaced.webp']);
        $this->getJson('/api/v1/banners')->assertJsonPath('banners.0.image', $banner->fresh()->image_url);
    }

    public function test_sea_units_route_and_active_hubs_are_validated_before_creating_departures(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $partner = DelivererCompany::create(['name' => 'Transit', 'service_type' => 'maritime', 'is_active' => true]);
        $from = MaritimeHub::create(['deliverer_company_id' => $partner->id, 'name' => 'Départ', 'country' => 'CN', 'city' => 'Guangzhou', 'address' => 'Privée', 'is_active' => true]);
        $to = MaritimeHub::create(['deliverer_company_id' => $partner->id, 'name' => 'Arrivée', 'country' => 'CM', 'city' => 'Douala', 'address' => 'Privée', 'is_active' => true]);
        $rateData = ['origin_country' => 'CN', 'destination_country' => 'CM', 'transport_mode' => 'sea', 'cargo_category' => 'general', 'rate_unit' => 'cbm', 'rate_amount' => 650, 'public_rate_amount' => 900, 'currency' => 'EUR', 'freight_payment_timing' => 'arrival'];
        $this->post(route('admin.maritime.freight-rates.store', $partner), array_replace($rateData, ['rate_unit' => 'kg']))->assertSessionHasErrors('rate_unit');
        $this->assertSame(0, PartnerFreightRate::count());
        $this->post(route('admin.maritime.freight-rates.store', $partner), $rateData)->assertSessionHasNoErrors();
        $rate = PartnerFreightRate::firstOrFail();
        $data = ['origin_hub_id' => $from->id, 'destination_hub_id' => $to->id, 'departure_date' => today()->addDays(30)->toDateString(), 'capacity_cbm' => 2.5, 'pricing_unit' => 'kg', 'price' => 650, 'currency' => 'EUR'];
        $url = route('admin.maritime.departures.store', $partner);
        $this->post($url, $data)->assertSessionHasErrors('pricing_unit');
        $this->post($url, $data + ['partner_freight_rate_id' => $rate->id])->assertSessionHasNoErrors();
        $departure = MaritimeDeparture::firstOrFail();
        $this->assertEquals(2500, $departure->remaining_kg);
        $this->assertEquals(900, $departure->publicFreightFor(1000));
        $this->assertEquals(650, $departure->partnerFreightFor(1000));
        $to->update(['is_active' => false]);
        $this->post($url, $data + ['partner_freight_rate_id' => $rate->id])->assertSessionHasErrors('partner_freight_rate_id');
        $this->post($url, array_replace($data, ['pricing_unit' => 'cbm']))->assertSessionHasErrors('destination_hub_id');
        $to->update(['is_active' => true, 'country' => 'FR']);
        $this->post($url, $data + ['partner_freight_rate_id' => $rate->id])->assertSessionHasErrors('partner_freight_rate_id');
        $this->assertSame(1, MaritimeDeparture::count());
    }

    public function test_catalogue_price_correction_preserves_custom_prices(): void
    {
        $partner = DelivererCompany::create(['name' => 'SinoCam Transit & Fret', 'service_type' => 'maritime']);
        $attributes = ['deliverer_company_id' => $partner->id, 'origin_country' => 'CN', 'destination_country' => 'CM', 'transport_mode' => 'sea', 'cargo_category' => 'general', 'rate_unit' => 'cbm', 'rate_amount' => 330000, 'public_rate_amount' => 450000, 'currency' => 'XAF', 'freight_payment_timing' => 'arrival'];
        $legacy = PartnerFreightRate::create($attributes);
        $custom = PartnerFreightRate::create(array_replace($attributes, ['cargo_category' => 'sensitive', 'rate_amount' => 700, 'public_rate_amount' => 950, 'currency' => 'EUR']));
        $oldSnapshot = $legacy->snapshot();
        $migration = require database_path('migrations/2026_10_27_070000_correct_sinocam_sea_catalogue_prices.php');
        $migration->up();
        $migration->up();
        $this->assertEquals(650, $legacy->fresh()->rate_amount);
        $this->assertEquals(900, $legacy->fresh()->public_rate_amount);
        $this->assertSame('EUR', $legacy->fresh()->currency);
        $this->assertEquals(700, $custom->fresh()->rate_amount);
        $this->assertEquals(950, $custom->fresh()->public_rate_amount);
        $this->assertEquals(330000, $oldSnapshot['rate_amount']);
    }

    public function test_validation_errors_use_one_shared_banner_on_admin_pages(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $errors = (new ViewErrorBag)->put('default', new MessageBag(['config' => 'unique-validation-marker']));
        foreach (['/admin/maritime', '/admin/products/create', '/admin/real-estate/listings', '/admin/import-countries'] as $url) {
            $response = $this->withSession(['errors' => $errors])->get($url)->assertOk();
            $this->assertSame(1, substr_count($response->getContent(), 'unique-validation-marker'), $url);
            $this->assertSame(1, substr_count($response->getContent(), 'id="adminFlash"'), $url);
            $response->assertSee('data-flash-type="error"', false);
        }
    }
}
