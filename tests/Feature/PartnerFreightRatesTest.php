<?php

namespace Tests\Feature;

use App\Models\Currency;
use App\Models\DelivererCompany;
use App\Models\MaritimeDeparture;
use App\Models\MaritimeHub;
use App\Models\ParcelNature;
use App\Models\PartnerFreightRate;
use App\Models\Setting;
use App\Models\User;
use App\Models\WalletBalance;
use App\Services\FirebaseMessagingService;
use Database\Seeders\SinoCamFreightRatesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PartnerFreightRatesTest extends TestCase
{
    use RefreshDatabase;

    private function hubs(DelivererCompany $partner, string $originCountry = 'CN', string $originCity = 'Guangzhou'): array
    {
        return [
            MaritimeHub::create(['deliverer_company_id' => $partner->id, 'name' => 'Dépôt privé départ', 'country' => $originCountry, 'city' => $originCity, 'address' => '123 rue privée', 'phone' => '+861234567890', 'is_active' => true]),
            MaritimeHub::create(['deliverer_company_id' => $partner->id, 'name' => 'Entrepôt privé arrivée', 'country' => 'CM', 'city' => 'Douala', 'address' => '456 rue privée', 'phone' => '+237699999999', 'is_active' => true]),
        ];
    }

    private function payload(array $hubs): array
    {
        return ['origin_hub_id' => $hubs[0]->id, 'destination_hub_id' => $hubs[1]->id,
            'departure_date' => today()->addDays(30)->toDateString(), 'capacity_kg' => 10000,
            'pricing_unit' => 'cbm', 'price' => 1, 'currency' => 'EUR'];
    }

    public function test_sea_and_air_rates_keep_partner_costs_separate_from_fixed_public_prices(): void
    {
        $this->seed(SinoCamFreightRatesSeeder::class);
        $this->seed(SinoCamFreightRatesSeeder::class);
        $partner = DelivererCompany::where('name', 'SinoCam Transit & Fret')->firstOrFail();
        $this->assertFalse((bool) $partner->is_active);
        $this->assertSame(3, PartnerFreightRate::count());
        $hubs = $this->hubs($partner);
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);
        Setting::set('maritime_commission_rate', 99); // Le prix fixe ne doit pas subir une deuxième majoration.
        foreach (PartnerFreightRate::all() as $rate) {
            $this->post(route('admin.maritime.departures.store', $partner), $this->payload($hubs) + ['partner_freight_rate_id' => $rate->id])
                ->assertRedirect()->assertSessionHasNoErrors();
            $departure = MaritimeDeparture::latest('id')->firstOrFail();
            $units = $rate->rate_unit === 'cbm' ? 0.1 : 100;
            $this->assertEquals((float) $rate->rate_amount * $units, $departure->partnerFreightFor(100));
            $this->assertEquals((float) $rate->public_rate_amount * $units, $departure->publicFreightFor(100));
            $this->assertSame($rate->transport_mode, $departure->toApi()['transport_mode']);
            $this->assertSame($rate->cargo_category, $departure->toApi()['cargo_category']);
            $this->assertSame(today()->addDays(30 + $rate->transit_days_max)->toDateString(), $departure->estimated_arrival_date->toDateString());
            $price = $departure->publicFreightFor(100);
            $rate->update(['public_rate_amount' => (float) $rate->public_rate_amount + 1000]);
            $this->assertEquals($price, $departure->fresh()->publicFreightFor(100));
        }
        $this->get(route('admin.maritime.partners.show', $partner))->assertOk()->assertSee('Prix client par unité');
        $this->post(route('admin.maritime.freight-rates.store', $partner), [
            'origin_country' => 'CN', 'destination_country' => 'CM', 'transport_mode' => 'air', 'cargo_category' => 'general',
            'rate_unit' => 'kg', 'rate_amount' => 12000, 'public_rate_amount' => 11000, 'currency' => 'XAF',
            'transit_days_min' => 14, 'transit_days_max' => 10, 'freight_payment_timing' => 'arrival',
        ])->assertSessionHasErrors(['public_rate_amount', 'transit_days_max']);
    }

    public function test_paris_departures_repeat_every_fourteen_days_and_china_remains_occasional(): void
    {
        $partner = DelivererCompany::create(['name' => 'International', 'service_type' => DelivererCompany::SERVICE_MARITIME, 'is_active' => true]);
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);
        $hubs = $this->hubs($partner, 'FR', 'Paris');
        $this->post(route('admin.maritime.departures.store', $partner), $this->payload($hubs) + ['occurrences' => 3])
            ->assertSessionHasNoErrors();
        $this->assertSame([30, 44, 58], MaritimeDeparture::orderBy('departure_date')->get()->map(fn ($d) => (int) today()->diffInDays($d->departure_date))->all());
        $china = $this->hubs($partner);
        $this->post(route('admin.maritime.departures.store', $partner), $this->payload($china) + ['occurrences' => 2])
            ->assertSessionHasErrors('occurrences');
        $this->assertSame(3, MaritimeDeparture::count());
    }

    public function test_freight_rate_deletion_checks_partner_and_admin_access(): void
    {
        $this->seed(SinoCamFreightRatesSeeder::class);
        $rate = PartnerFreightRate::firstOrFail();
        $partner = $rate->company;
        $url = route('admin.maritime.freight-rates.destroy', [$partner, $rate]);
        $this->delete($url)->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create(['role' => 'client']), 'web')->delete($url)->assertRedirect(route('admin.login'));
        $this->assertDatabaseHas('partner_freight_rates', ['id' => $rate->id]);

        $admin = User::factory()->create(['role' => 'admin']);
        $other = DelivererCompany::create(['name' => 'Autre partenaire', 'service_type' => DelivererCompany::SERVICE_MARITIME, 'is_active' => true]);
        $this->actingAs($admin, 'web')->delete(route('admin.maritime.freight-rates.destroy', [$other, $rate]))->assertNotFound();
        $this->assertDatabaseHas('partner_freight_rates', ['id' => $rate->id]);
        $this->get(route('admin.maritime.partners.show', $partner))->assertOk()
            ->assertSee('Supprimer le tarif')->assertSee('data-confirm=', false)->assertSee($url, false);
        $this->from(route('admin.maritime.partners.show', $partner))->delete($url)
            ->assertRedirect(route('admin.maritime.partners.show', $partner))->assertSessionHas('success');
        $this->assertDatabaseMissing('partner_freight_rates', ['id' => $rate->id]);
        $this->assertSame(2, PartnerFreightRate::count());
        $this->delete($url)->assertNotFound();
    }

    public function test_meeting_locations_are_only_returned_to_the_customer_after_payment_and_partner_stays_anonymous(): void
    {
        Mail::fake();
        $this->mock(FirebaseMessagingService::class, fn ($m) => $m->shouldReceive('sendToUser')->andReturn([]));
        Currency::firstOrCreate(['code' => 'XAF'], ['name' => 'Franc CFA', 'symbol' => 'FCFA', 'is_active' => true]);
        Setting::set('maritime_commission_rate', 10);
        $this->seed(SinoCamFreightRatesSeeder::class);
        $partner = DelivererCompany::where('name', 'SinoCam Transit & Fret')->firstOrFail();
        $partner->update(['is_active' => true]);
        $hubs = $this->hubs($partner);
        $admin = User::factory()->create(['role' => 'admin']);
        $rate = PartnerFreightRate::where('transport_mode', 'air')->firstOrFail();
        $this->actingAs($admin)->post(route('admin.maritime.departures.store', $partner), $this->payload($hubs) + ['partner_freight_rate_id' => $rate->id])->assertSessionHasNoErrors();
        $departure = MaritimeDeparture::firstOrFail();
        $buyer = User::factory()->create();
        WalletBalance::create(['user_id' => $buyer->id, 'currency' => 'XAF', 'balance' => 1000000, 'locked_balance' => 0]);
        $nature = ParcelNature::create(['name' => 'Vêtements', 'insurance_rate' => 1, 'is_active' => true]);
        $this->actingAs($buyer, 'sanctum');
        $url = "/api/v1/maritime/departures/{$departure->id}";
        $this->getJson($url)->assertOk()->assertJsonPath('data.partner', null)
            ->assertJsonPath('data.origin_hub.name', null)->assertJsonPath('data.origin_hub.address', null)
            ->assertJsonPath('data.destination_hub.phone', null)->assertJsonPath('data.meeting_details_available', false);
        $this->getJson('/api/v1/maritime/departures?transport_mode=sea')->assertOk()->assertJsonCount(0, 'data.data');
        $this->getJson('/api/v1/maritime/departures?transport_mode=air')->assertOk()->assertJsonCount(1, 'data.data');
        $booking = $this->postJson("{$url}/book", [
            'kg_booked' => 10, 'parcels_count' => 1, 'sender_first_name' => 'Jean', 'sender_last_name' => 'Test',
            'sender_phone' => '+237699999999', 'emergency_phone' => '+237699999998',
            'recipient_first_name' => 'Marie', 'recipient_last_name' => 'Test', 'recipient_country' => 'CM',
            'recipient_phone' => '+237699999997', 'parcel_nature_id' => $nature->id,
            'declared_value' => 100000, 'insurance_opt_in' => false, 'payment_method' => 'wallet',
        ])->assertCreated()->assertJsonPath('data.total_price', 200000)
            ->assertJsonPath('data.departure.meeting_details_available', true)->json('data.id');
        $this->getJson($url)->assertOk()->assertJsonPath('data.partner', null)
            ->assertJsonPath('data.origin_hub.address', '123 rue privée')->assertJsonPath('data.meeting_details_available', true);
        $this->actingAs(User::factory()->create(), 'sanctum')->getJson($url)->assertOk()->assertJsonPath('data.origin_hub.address', null);
        // Supprimer le catalogue ne change ni le prix convenu ni l'envoi payé.
        $snapshot = $departure->freight_rate_snapshot;
        $this->actingAs($admin, 'web')->delete(route('admin.maritime.freight-rates.destroy', [$partner, $rate]))->assertRedirect();
        $this->assertDatabaseMissing('partner_freight_rates', ['id' => $rate->id]);
        $this->assertNull($departure->fresh()->partner_freight_rate_id);
        $this->assertSame($snapshot, $departure->fresh()->freight_rate_snapshot);
        $this->assertEquals(200000, $departure->fresh()->publicFreightFor(10));
        $this->assertDatabaseHas('maritime_bookings', ['id' => $booking, 'payment_status' => 'completed', 'total_price' => 200000]);
        $this->actingAs($buyer, 'sanctum')->postJson("/api/v1/maritime/bookings/{$booking}/cancel")->assertOk();
        $this->getJson($url)->assertOk()->assertJsonPath('data.meeting_details_available', false);
    }
}
