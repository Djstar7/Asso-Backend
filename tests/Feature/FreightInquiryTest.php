<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Currency;
use App\Models\DelivererCompany;
use App\Models\FreightInquiry;
use App\Models\MaritimeBooking;
use App\Models\MaritimeDeparture;
use App\Models\MaritimeHub;
use App\Models\ParcelNature;
use App\Models\PartnerFreightRate;
use App\Models\Setting;
use App\Models\User;
use App\Services\FirebaseMessagingService;
use App\Services\WalletService;
use Database\Seeders\InternationalTransportProductionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FreightInquiryTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $buyer;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Mail::fake();
        $this->mock(FirebaseMessagingService::class, fn ($m) => $m->shouldReceive('sendToUser')->andReturn([]));
        $this->admin = User::factory()->create(['email' => 'admin@asso.com', 'role' => 'admin']);
        $this->buyer = User::factory()->create();
        Setting::set('support_user_id', $this->admin->id);
        Currency::firstOrCreate(['code' => 'XAF'], ['name' => 'FCFA', 'symbol' => 'FCFA', 'is_active' => true]);
    }

    private function inquiry(): FreightInquiry
    {
        Sanctum::actingAs($this->buyer);
        $id = $this->postJson('/api/v1/maritime/inquiries', [
            'destination_country' => 'FR', 'kg' => 10, 'volume_cbm' => 0.1,
            'cargo_category' => 'general', 'description' => 'Vêtements à destination de Paris',
        ])->assertCreated()->assertJsonMissingPath('data.partner_amount')->json('data.id');

        return FreightInquiry::findOrFail($id);
    }

    private function departure(): MaritimeDeparture
    {
        $company = DelivererCompany::create(['name' => 'Partenaire privé', 'service_type' => 'maritime', 'is_active' => true]);
        $from = MaritimeHub::create(['deliverer_company_id' => $company->id, 'name' => 'Dépôt privé', 'country' => 'CN', 'city' => 'Guangzhou', 'address' => 'Adresse privée', 'is_active' => true]);
        $to = MaritimeHub::create(['deliverer_company_id' => $company->id, 'name' => 'Arrivée privée', 'country' => 'FR', 'city' => 'Paris', 'address' => 'Adresse privée', 'is_active' => true]);

        return MaritimeDeparture::create(['deliverer_company_id' => $company->id, 'origin_hub_id' => $from->id, 'destination_hub_id' => $to->id,
            'departure_date' => today()->addDays(30), 'capacity_kg' => 100, 'remaining_kg' => 100, 'price_per_kg' => 1000, 'currency' => 'XAF', 'status' => 'open']);
    }

    private function propose(FreightInquiry $inquiry, MaritimeDeparture $departure): void
    {
        $this->actingAs($this->admin)->post(route('admin.maritime.inquiries.quote', $inquiry->id), [
            'maritime_departure_id' => $departure->id, 'freight_amount' => 15000, 'expires_at' => now()->addDay()->toDateTimeString(),
        ])->assertRedirect()->assertSessionHasNoErrors();
        Sanctum::actingAs($this->buyer);
    }

    private function bookingData(array $extra = []): array
    {
        $nature = ParcelNature::firstOrCreate(['name' => 'Vêtements'], ['insurance_rate' => 1, 'is_active' => true]);

        return $extra + ['kg_booked' => 10, 'parcels_count' => 1,
            'sender_first_name' => 'Jean', 'sender_last_name' => 'Test', 'sender_phone' => '+237699999999', 'emergency_phone' => '+237699999998',
            'recipient_first_name' => 'Marie', 'recipient_last_name' => 'Test', 'recipient_country' => 'FR', 'recipient_phone' => '+33612345678',
            'parcel_nature_id' => $nature->id, 'declared_value' => 100000, 'insurance_opt_in' => false, 'payment_method' => 'wallet'];
    }

    public function test_negotiation_acceptance_and_payment_use_the_agreed_price_once(): void
    {
        $inquiry = $this->inquiry();
        $departure = $this->departure();
        $this->propose($inquiry, $departure);
        $this->getJson('/api/v1/maritime/inquiries')->assertOk()->assertJsonPath('data.0.freight_amount', 15000)
            ->assertJsonPath('data.0.departure.price_per_kg', null)->assertDontSee('Adresse privée')->assertDontSee('Partenaire privé');
        $this->postJson("/api/v1/maritime/inquiries/{$inquiry->id}/accept", ['expected_amount' => 14000, 'expected_currency' => 'XAF'])->assertUnprocessable();
        $this->postJson("/api/v1/maritime/inquiries/{$inquiry->id}/accept", ['expected_amount' => 15000, 'expected_currency' => 'XAF'])->assertOk()->assertJsonPath('data.status', 'accepted');
        // Modifier le catalogue après acceptation ne change pas le prix négocié.
        $departure->update(['price_per_kg' => 1200]);
        app(WalletService::class)->credit($this->buyer, 100000);
        $url = "/api/v1/maritime/departures/{$departure->id}/book";
        $this->postJson($url, $this->bookingData())->assertUnprocessable();
        $this->postJson($url, $this->bookingData(['freight_inquiry_id' => $inquiry->id, 'kg_booked' => 11]))->assertUnprocessable();
        $id = $this->postJson($url, $this->bookingData(['freight_inquiry_id' => $inquiry->id]))->assertCreated()
            ->assertJsonPath('data.total_price', 15000)->assertJsonPath('data.status', 'paid')->json('data.id');
        $this->assertDatabaseHas('maritime_bookings', ['id' => $id, 'freight_subtotal' => 10000, 'commission_amount' => 5000, 'total_price' => 15000]);
        $snapshot = MaritimeBooking::findOrFail($id)->freight_rate_snapshot;
        $this->assertEquals(1000000, $snapshot['rate_amount']);
        $this->assertEquals(1500000, $snapshot['public_rate_amount']);
        $this->assertSame($inquiry->id, $snapshot['freight_inquiry_id']);
        $this->assertSame('booked', $inquiry->fresh()->status);
        $this->postJson($url, $this->bookingData(['freight_inquiry_id' => $inquiry->id]))->assertUnprocessable();
        $this->assertSame(1, MaritimeBooking::count());
        $this->assertEquals(90, $departure->fresh()->remaining_kg);
    }

    public function test_other_users_expired_quotes_and_changed_routes_are_rejected(): void
    {
        $inquiry = $this->inquiry();
        $departure = $this->departure();
        $this->propose($inquiry, $departure);
        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/v1/maritime/inquiries')->assertJsonCount(0, 'data');
        $this->postJson("/api/v1/maritime/inquiries/{$inquiry->id}/accept", ['expected_amount' => 15000, 'expected_currency' => 'XAF'])->assertNotFound();
        Sanctum::actingAs($this->buyer);
        $inquiry->update(['expires_at' => now()->subMinute()]);
        $this->postJson("/api/v1/maritime/inquiries/{$inquiry->id}/accept", ['expected_amount' => 15000, 'expected_currency' => 'XAF'])->assertUnprocessable();
        $departure->destinationHub->update(['country' => 'US']);
        $this->actingAs($this->admin)->post(route('admin.maritime.inquiries.quote', $inquiry->id), [
            'maritime_departure_id' => $departure->id, 'freight_amount' => 15000, 'expires_at' => now()->addDay()->toDateTimeString(),
        ])->assertUnprocessable();
        $this->actingAs($this->buyer, 'web')->get(route('admin.maritime.inquiries.index'))->assertRedirect(route('admin.login'));
        $this->assertSame(0, MaritimeBooking::count());
    }

    public function test_inquiries_create_a_real_asso_conversation_and_reject_cameroon(): void
    {
        $first = $this->inquiry();
        $this->inquiry();
        $this->assertSame(1, Conversation::count());
        $this->assertDatabaseHas('messages', ['conversation_id' => $first->conversation_id, 'sender_id' => $this->buyer->id]);
        $this->postJson('/api/v1/maritime/inquiries', ['destination_country' => 'CM', 'kg' => 10, 'cargo_category' => 'general', 'description' => 'Un colis'])->assertUnprocessable();
        $this->actingAs($this->admin)->get(route('admin.maritime.inquiries.index'))->assertOk()->assertSee('Vêtements');
    }

    public function test_production_seeder_is_idempotent_preserves_edits_and_uses_default_france_margin(): void
    {
        Setting::set('maritime_commission_rate', 5);
        $this->seed(InternationalTransportProductionSeeder::class);
        $this->assertSame(5, PartnerFreightRate::count());
        $rate = PartnerFreightRate::where('origin_country', 'FR')->where('transport_mode', 'sea')->firstOrFail();
        $this->assertEquals(900, $rate->rate_amount);
        $this->assertNull($rate->public_rate_amount);
        $this->assertNull($rate->transit_days_max);
        $this->assertNotNull($rate->company->account_user_id);
        $departure = new MaritimeDeparture(['price_per_cbm' => 900, 'kg_per_cbm' => 1000, 'freight_rate_snapshot' => $rate->snapshot()]);
        $this->assertSame(945.0, $departure->publicFreightFor(1000));
        $rate->update(['rate_amount' => 950, 'is_active' => false]);
        $this->seed(InternationalTransportProductionSeeder::class);
        $this->assertSame(5, PartnerFreightRate::count());
        $this->assertEquals(950, $rate->fresh()->rate_amount);
        $this->assertFalse($rate->fresh()->is_active);
        $this->assertSame(0, MaritimeDeparture::count());
        $this->assertSame(0, MaritimeHub::count());
    }

    public function test_production_cleanup_only_disables_tagged_partner_demo_data(): void
    {
        $this->seed(InternationalTransportProductionSeeder::class);
        $partner = DelivererCompany::where('name', 'SinoCam Transit & Fret')->firstOrFail();
        $demo = MaritimeHub::create(['deliverer_company_id' => $partner->id, 'name' => 'Démo', 'country' => 'CN', 'city' => 'Test', 'address' => 'Adresse de démonstration', 'is_demo' => true, 'is_active' => true]);
        $real = MaritimeHub::create(['deliverer_company_id' => $partner->id, 'name' => 'Réel', 'country' => 'CM', 'city' => 'Douala', 'address' => 'Adresse réelle', 'is_demo' => false, 'is_active' => true]);
        $attributes = ['deliverer_company_id' => $partner->id, 'origin_hub_id' => $demo->id, 'destination_hub_id' => $real->id,
            'departure_date' => today()->addDays(30), 'capacity_kg' => 100, 'remaining_kg' => 100, 'price_per_kg' => 12000, 'currency' => 'XAF', 'status' => 'open'];
        $demoDeparture = MaritimeDeparture::create($attributes + ['is_demo' => true]);
        $realDeparture = MaritimeDeparture::create($attributes + ['is_demo' => false]);
        $this->seed(InternationalTransportProductionSeeder::class);
        $this->assertFalse($demo->fresh()->is_active);
        $this->assertTrue($real->fresh()->is_active);
        $this->assertSame('closed', $demoDeparture->fresh()->status);
        $this->assertSame('open', $realDeparture->fresh()->status);
        $this->assertSame(2, MaritimeHub::count());
        $this->assertSame(2, MaritimeDeparture::count());
    }

    public function test_china_cameroon_partner_freight_is_credited_at_arrival_without_double_credit_at_handover(): void
    {
        $departure = $this->departure();
        $departure->destinationHub->update(['country' => 'CM']);
        $departure->update(['freight_rate_snapshot' => [
            'transport_mode' => 'air', 'freight_payment_timing' => 'arrival', 'public_rate_amount' => 2000,
        ]]);
        app(WalletService::class)->credit($this->buyer, 100000);
        Sanctum::actingAs($this->buyer);
        $id = $this->postJson("/api/v1/maritime/departures/{$departure->id}/book", $this->bookingData(['recipient_country' => 'CM']))
            ->assertCreated()->assertJsonPath('data.total_price', 20000)->json('data.id');
        $booking = MaritimeBooking::findOrFail($id);
        $service = app(\App\Services\MaritimeBookingService::class);
        $this->assertSame('arrival', $booking->partner_freight_payment_timing);
        $this->assertNull($booking->partner_freight_paid_at);
        $service->advance($booking); // Déposé
        $service->advance($booking); // Embarqué
        $this->assertNull($booking->fresh()->partner_freight_paid_at);
        $service->advance($booking); // Arrivé
        $this->assertNotNull($booking->fresh()->partner_freight_paid_at);
        $this->assertEquals(10000, $booking->fresh()->partner_freight_paid_xaf);
        $partnerAccount = $departure->company->fresh()->account;
        $this->assertEquals(10000, $partnerAccount->kpayAvailableFor('XAF'));
        $service->complete($booking->fresh(), $booking->confirmation_code);
        $this->assertEquals(10000, $partnerAccount->fresh()->kpayAvailableFor('XAF'));
        $this->assertSame(1, \App\Models\WalletTransaction::where('user_id', $partnerAccount->id)
            ->where('metadata->partner_reference', 'maritime_booking:'.$id)->count());
        $this->assertSame('completed', $booking->fresh()->status);
    }
}
