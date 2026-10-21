<?php

namespace Tests\Feature;

use App\Models\DelivererCompany;
use App\Models\MaritimeBooking;
use App\Models\MaritimeDeparture;
use App\Models\MaritimeHub;
use App\Models\PartnerFreightRate;
use App\Models\User;
use App\Services\MaritimeBookingException;
use App\Services\MaritimeBookingService;
use Database\Seeders\SinoCamTransportDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TransportConfigurationTest extends TestCase
{
    use RefreshDatabase;

    public function test_production_configuration_adds_only_missing_defaults_and_preserves_admin_changes(): void
    {
        $this->app->instance('env', 'production');
        $this->artisan('transport:configure')->assertSuccessful();
        $partner = DelivererCompany::where('name', 'SinoCam Transit & Fret')->firstOrFail();
        $this->assertFalse((bool) $partner->is_active);
        $this->assertSame(3, PartnerFreightRate::count());
        $this->assertSame(0, MaritimeHub::count());
        $this->assertSame(0, MaritimeDeparture::count());
        $rate = PartnerFreightRate::where('transport_mode', 'air')->firstOrFail();
        $rate->update(['public_rate_amount' => 22000, 'is_active' => false]);
        $partner->update(['description' => 'Conditions modifiées par l’admin', 'is_active' => true]);
        $this->artisan('transport:configure')->assertSuccessful();
        $this->assertSame(3, PartnerFreightRate::count());
        $this->assertEquals(22000, $rate->fresh()->public_rate_amount);
        $this->assertFalse($rate->fresh()->is_active);
        $this->assertTrue((bool) $partner->fresh()->is_active);
        $this->assertSame('Conditions modifiées par l’admin', $partner->fresh()->description);
    }

    public function test_local_demo_has_three_explicit_test_hubs_and_departures_without_resetting_capacity(): void
    {
        $this->artisan('transport:configure', ['--demo' => true])->assertSuccessful();
        $this->assertSame(['CM', 'CN', 'FR'], MaritimeHub::orderBy('city')->pluck('country')->all());
        $this->assertSame(3, MaritimeHub::where('is_demo', true)->count());
        $this->assertSame(3, MaritimeDeparture::where('is_demo', true)->count());
        foreach (MaritimeHub::all() as $hub) {
            $this->assertStringStartsWith('[TEST]', $hub->name);
            $this->assertStringContainsString('FICTIVE', $hub->address);
        }
        foreach (MaritimeDeparture::with(['company', 'originHub', 'destinationHub'])->get() as $departure) {
            $this->assertTrue($departure->isBookable());
            $this->assertNull($departure->freight_rate_snapshot['frequency_days']);
        }
        $air = MaritimeDeparture::where('freight_rate_snapshot->transport_mode', 'air')->firstOrFail();
        $this->assertEquals(120000, $air->partnerFreightFor(10));
        $this->assertEquals(200000, $air->publicFreightFor(10));
        $air->decrement('remaining_kg', 1);
        $date = $air->departure_date->toDateString();
        $this->artisan('transport:configure', ['--demo' => true])->assertSuccessful();
        $this->assertSame(3, MaritimeHub::count());
        $this->assertSame(3, MaritimeDeparture::count());
        $this->assertEquals(99, $air->fresh()->remaining_kg);
        $this->assertSame($date, $air->fresh()->departure_date->toDateString());
        $this->assertSame(0, MaritimeBooking::count());
    }

    public function test_demo_command_and_direct_seeder_refuse_production_before_any_write(): void
    {
        $this->app->instance('env', 'production');
        $this->artisan('transport:configure', ['--demo' => true])->assertFailed();
        try {
            (new SinoCamTransportDemoSeeder)->run();
            $this->fail('Le seeder de démonstration doit refuser la production.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('local et testing', $e->getMessage());
        }
        $this->assertSame(0, DelivererCompany::count());
        $this->assertSame(0, PartnerFreightRate::count());
        $this->assertSame(0, MaritimeHub::count());
        $this->assertSame(0, MaritimeDeparture::count());
    }

    public function test_demo_departures_are_hidden_and_not_bookable_in_production_even_after_database_copy(): void
    {
        $this->artisan('transport:configure', ['--demo' => true])->assertSuccessful();
        $id = MaritimeDeparture::firstOrFail()->id;
        $user = User::factory()->create();
        $this->app->instance('env', 'production');
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/maritime/departures')
            ->assertOk()->assertJsonCount(0, 'data.data');
        $this->getJson("/api/v1/maritime/departures/{$id}")->assertNotFound();
        $this->assertBookingRefused($user, $id);
        $this->assertSame(0, MaritimeBooking::count());
    }

    public function test_inactive_partners_or_hubs_and_wrong_recipient_country_cannot_create_a_booking(): void
    {
        $this->artisan('transport:configure', ['--demo' => true])->assertSuccessful();
        $user = User::factory()->create();
        $departure = MaritimeDeparture::with(['company', 'originHub', 'destinationHub'])->firstOrFail();
        $departure->company->update(['is_active' => false]);
        $this->assertBookingRefused($user, $departure->id);
        $departure->company->update(['is_active' => true]);
        $departure->originHub->update(['is_active' => false]);
        $this->assertBookingRefused($user, $departure->id);
        $departure->originHub->update(['is_active' => true]);
        $this->assertBookingRefused($user, $departure->id, 'FR', 'recipient_country');
        $this->assertSame(0, MaritimeBooking::count());
    }

    private function assertBookingRefused(User $user, int $departureId, string $country = 'CM', ?string $field = null): void
    {
        try {
            app(MaritimeBookingService::class)->book($user, $departureId, ['kg_booked' => 10, 'recipient_country' => $country]);
            $this->fail('La réservation doit être refusée avant paiement.');
        } catch (MaritimeBookingException $e) {
            $this->assertSame($field, $e->field());
        }
    }
}
