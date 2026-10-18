<?php

namespace Tests\Feature;

use App\Models\Currency;
use App\Models\DelivererCompany;
use App\Models\ExchangeRate;
use App\Models\MaritimeBooking;
use App\Models\MaritimeDeparture;
use App\Models\MaritimeHub;
use App\Models\ParcelNature;
use App\Models\ServiceConfiguration;
use App\Models\Setting;
use App\Models\User;
use App\Services\FirebaseMessagingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Diaspo Maritime : réservation sur un départ, clôture J − 7, paiement →
 * équipe prévenue, étapes, remise au code (fret dû au partenaire, commission
 * + assurance à ASSO), remboursement des colis non déposés.
 */
class MaritimeBookingTest extends TestCase
{
    use RefreshDatabase;

    private array $pushes = [];

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Mail::fake();
        ServiceConfiguration::updateOrCreate(
            ['service_name' => 'kpay'],
            ['service_type' => 'payment', 'is_active' => true, 'configuration' => ['api_key' => 'k', 'secret_key' => 's', 'base_url' => 'https://kpay.test']]
        );
        $this->mock(FirebaseMessagingService::class, function ($m) {
            $m->shouldReceive('sendToUser')->andReturnUsing(function ($user, $title, $body, $data = []) {
                $this->pushes[] = ['user_id' => $user->id, 'type' => $data['type'] ?? null];
                if (($data['type'] ?? null) === 'maritime_arrived') {
                    (new FirebaseMessagingService)->sendToUser($user, $title, $body, $data);
                }

                return [];
            });
        });

        Currency::firstOrCreate(['code' => 'XAF'], ['name' => 'Franc CFA', 'symbol' => 'FCFA', 'is_active' => true]);
        Currency::firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'symbol' => '€', 'is_active' => true]);
        ExchangeRate::create([
            'from_currency' => 'EUR', 'to_currency' => 'XAF', 'rate' => 656,
            'effective_date' => now()->toDateString(), 'is_active' => true, 'source' => 'test',
        ]);

        Setting::set('maritime_commission_rate', 10);
        Setting::set('maritime_booking_cutoff_days', 7);
        Setting::set('maritime_dropoff_days_before', 4);
        User::factory()->create(['email' => 'platform@asso.test']);
        Setting::set('platform_account_email', 'platform@asso.test');
    }

    private function departure(int $daysAhead = 20, ?User $partnerUser = null): MaritimeDeparture
    {
        $partner = DelivererCompany::create([
            'name' => 'Transit Mer '.uniqid(),
            'service_type' => DelivererCompany::SERVICE_MARITIME,
            'is_active' => true,
            'user_id' => ($partnerUser ?? User::factory()->create())->id,
        ]);
        $from = MaritimeHub::create(['deliverer_company_id' => $partner->id, 'name' => 'QG Le Havre', 'country' => 'FR', 'city' => 'Le Havre', 'address' => '1 quai', 'is_active' => true]);
        $to = MaritimeHub::create(['deliverer_company_id' => $partner->id, 'name' => 'QG Douala', 'country' => 'CM', 'city' => 'Douala', 'address' => 'Port', 'is_active' => true]);

        return MaritimeDeparture::create([
            'deliverer_company_id' => $partner->id,
            'origin_hub_id' => $from->id,
            'destination_hub_id' => $to->id,
            'departure_date' => today()->addDays($daysAhead),
            'capacity_kg' => 500, 'remaining_kg' => 500,
            'price_per_kg' => 4, 'currency' => 'EUR', 'status' => 'open',
        ]);
    }

    private function payload(array $extra = []): array
    {
        $nature = ParcelNature::firstOrCreate(['name' => 'Chaussures test'], ['insurance_rate' => 2, 'is_active' => true]);

        return $extra + [
            'kg_booked' => 50,
            'parcels_count' => 3,
            'sender_first_name' => 'Awa', 'sender_last_name' => 'Ndiaye', 'sender_phone' => '+33600000000',
            'emergency_phone' => '+33611111111',
            'recipient_first_name' => 'Paul', 'recipient_last_name' => 'Etoa', 'recipient_country' => 'CM', 'recipient_phone' => '+237690000000',
            'parcel_nature_id' => $nature->id,
            'declared_value' => 1000,
            'insurance_opt_in' => true,
            'payment_method' => 'kpay', 'provider' => 'MTN_MOMO_CMR', 'phone_number' => '237653456789',
        ];
    }

    private function fakeKpay(string $status = 'SUCCESS'): void
    {
        Http::fake([
            '*/api/v1/payments/init' => Http::response(['id' => 'pay_sea_1', 'status' => 'PENDING'], 200),
            '*/api/v1/payments/*' => Http::response(['status' => $status], 200),
        ]);
    }

    public function test_full_lifecycle_credits_partner_wallet_and_asso(): void
    {
        $this->fakeKpay();
        $admin = User::factory()->create(['role' => 'admin', 'email' => 'boss@asso.test']);
        $partnerUser = User::factory()->create();
        $departure = $this->departure(20, $partnerUser);
        $sender = User::factory()->create();

        Sanctum::actingAs($sender);
        $res = $this->postJson("/api/v1/maritime/departures/{$departure->id}/book", $this->payload())->assertStatus(201);
        $id = $res->json('booking_id');

        // À l'initialisation KPay est en attente : le devis est figé, mais la prime payée reste à zéro.
        $this->assertDatabaseHas('maritime_bookings', [
            'id' => $id, 'freight_subtotal' => 200, 'commission_amount' => 20, 'insurance_amount' => 0,
            'insurance_opt_in' => true, 'insurance_rate_applied' => 2, 'insurance_quote_amount' => 20, 'total_price' => 240,
            'dropoff_deadline' => today()->addDays(16)->toDateString().' 00:00:00',
        ]);
        $res->assertJsonPath('data.insurance_opt_in', true)
            ->assertJsonPath('data.insurance_amount', 0)
            ->assertJsonPath('data.insurance_status', 'pending_payment');
        $this->assertEquals(450, (float) $departure->fresh()->remaining_kg);

        $this->getJson("/api/v1/maritime/bookings/{$id}/payment-status")->assertJsonPath('data.status', 'paid');
        $this->assertDatabaseHas('maritime_bookings', ['id' => $id, 'insurance_amount' => 20, 'insurance_status' => 'active']);

        // L'équipe ASSO est prévenue pour contacter l'expéditeur.
        $this->assertContains(['user_id' => $admin->id, 'type' => 'maritime_staff_paid'], $this->pushes);
        $this->assertContains(['user_id' => $sender->id, 'type' => 'maritime_booking_paid'], $this->pushes);

        $booking = MaritimeBooking::find($id);
        $this->actingAs($admin);
        $this->get(route('admin.maritime.bookings.index'))->assertOk()->assertSee('Awa Ndiaye');
        $this->get(route('admin.maritime.bookings.show', $booking))->assertOk()->assertSee('+33611111111');
        foreach (['dropped_off', 'shipped', 'arrived'] as $step) {
            $this->post(route('admin.maritime.bookings.advance', $booking))->assertRedirect();
            $this->assertEquals($step, $booking->fresh()->status);
        }
        // Replayed arrival transition is rejected without a duplicate client notification.
        $this->post(route('admin.maritime.bookings.advance', $booking))->assertSessionHas('error');
        $this->assertSame(1, collect($this->pushes)->where('type', 'maritime_arrived')->count());
        $this->assertNotNull($booking->fresh()->arrived_at);
        $this->assertDatabaseCount('notifications', 1);
        $this->assertDatabaseHas('notifications', ['user_id' => $sender->id, 'type' => 'maritime_arrived']);

        $this->post(route('admin.maritime.bookings.complete', $booking), ['confirmation_code' => $booking->confirmation_code === '000000' ? '111111' : '000000'])
            ->assertSessionHas('error');
        $this->post(route('admin.maritime.bookings.complete', $booking), ['confirmation_code' => $booking->confirmation_code])
            ->assertSessionHas('success');

        $this->assertEquals('completed', $booking->fresh()->status);
        // Fret 200 € → 131 200 FCFA au Wallet du compte interne du partenaire ;
        // commission 13 120 + assurance 13 120 à ASSO.
        $this->assertEquals(0, $partnerUser->fresh()->kpayBalanceFor('XAF'));
        $this->assertEquals(131200, (\App\Models\DelivererCompany::find($departure->deliverer_company_id)?->account?->kpayBalanceFor('XAF') ?? 0));
        $platform = User::where('email', 'platform@asso.test')->first();
        $this->assertEquals(26240, $platform->kpayBalanceFor('XAF'));
    }

    public function test_shipment_paid_with_wallet(): void
    {
        $departure = $this->departure();
        $sender = User::factory()->create();
        $sender->creditKpay('XAF', 200000);
        Sanctum::actingAs($sender);

        $payload = ['payment_method' => 'wallet'] + $this->payload();
        unset($payload['provider'], $payload['phone_number']);
        // 240 € = 157 440 FCFA débités, envoi payé tout de suite.
        $walletBooking = $this->postJson("/api/v1/maritime/departures/{$departure->id}/book", $payload)
            ->assertStatus(201)
            ->assertJsonPath('payment.flow', 'wallet')
            ->assertJsonPath('data.status', 'paid');
        $walletBooking->assertJsonPath('data.insurance_status', 'active')
            ->assertJsonPath('data.insurance_amount', 20);
        $this->assertEquals(200000 - 157440, $sender->fresh()->kpayBalanceFor('XAF'));

        // Solde restant insuffisant pour un second envoi : refusé, kilos rendus.
        $this->postJson("/api/v1/maritime/departures/{$departure->id}/book", $payload)->assertStatus(422);
        $this->assertEquals(450, (float) $departure->fresh()->remaining_kg);
    }

    public function test_booking_refused_inside_cutoff_window(): void
    {
        $departure = $this->departure(6); // clôture à J − 7 : déjà passée
        Sanctum::actingAs(User::factory()->create());

        $this->postJson("/api/v1/maritime/departures/{$departure->id}/book", $this->payload())
            ->assertStatus(422)
            ->assertJsonPath('message', 'Trajet impossible à faire, veuillez consulter les prochaines dates pour cette destination.');

        $this->getJson("/api/v1/maritime/departures/{$departure->id}")
            ->assertJsonPath('data.is_bookable', false);
    }

    public function test_booking_refused_beyond_remaining_kg(): void
    {
        $departure = $this->departure();
        Sanctum::actingAs(User::factory()->create());

        $this->postJson("/api/v1/maritime/departures/{$departure->id}/book", $this->payload(['kg_booked' => 600]))
            ->assertStatus(422);
    }

    public function test_failed_payment_restores_kg(): void
    {
        $this->fakeKpay('FAILED');
        $departure = $this->departure();
        Sanctum::actingAs(User::factory()->create());

        $id = $this->postJson("/api/v1/maritime/departures/{$departure->id}/book", $this->payload())->json('booking_id');
        $this->getJson("/api/v1/maritime/bookings/{$id}/payment-status")->assertJsonPath('data.status', 'cancelled');
        $this->assertEquals(500, (float) $departure->fresh()->remaining_kg);
    }

    public function test_missed_dropoff_refunds_total_minus_commission(): void
    {
        $this->fakeKpay();
        $departure = $this->departure();
        $sender = User::factory()->create();
        Sanctum::actingAs($sender);
        $id = $this->postJson("/api/v1/maritime/departures/{$departure->id}/book", $this->payload())->json('booking_id');
        $this->getJson("/api/v1/maritime/bookings/{$id}/payment-status");

        MaritimeBooking::whereKey($id)->update(['dropoff_deadline' => today()->subDay()]);
        $this->artisan('maritime:refund-missed-dropoffs')->assertSuccessful();

        $b = MaritimeBooking::find($id);
        $this->assertEquals('cancelled', $b->status);
        $this->assertEquals('refunded', $b->payment_status);
        // 240 − 20 de commission = 220 € → 144 320 FCFA.
        $this->assertEquals(144320, $sender->fresh()->kpayBalanceFor('XAF'));
        $this->assertContains(['user_id' => $sender->id, 'type' => 'maritime_dropoff_missed'], $this->pushes);
    }

    public function test_sender_cancel_before_dropoff_refunds_everything(): void
    {
        $this->fakeKpay();
        $departure = $this->departure();
        $sender = User::factory()->create();
        Sanctum::actingAs($sender);
        $id = $this->postJson("/api/v1/maritime/departures/{$departure->id}/book", $this->payload())->json('booking_id');
        $this->getJson("/api/v1/maritime/bookings/{$id}/payment-status");

        $this->postJson("/api/v1/maritime/bookings/{$id}/cancel")->assertOk();
        $this->assertEquals(157440, $sender->fresh()->kpayBalanceFor('XAF'));
        $this->assertEquals(500, (float) $departure->fresh()->remaining_kg);
    }

    public function test_admin_manages_maritime_partner_apart_from_delivery(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $this->post(route('admin.maritime.partners.store'), ['name' => 'Marins', 'phone' => '674693625'])->assertRedirect();
        $partner = DelivererCompany::where('name', 'Marins')->firstOrFail();
        $this->assertEquals(DelivererCompany::SERVICE_MARITIME, $partner->service_type);

        $this->get(route('admin.maritime.partners.show', $partner))->assertOk(); // consomme le message de création

        // Hors de la livraison locale : ni dans Partenaires logistiques ni dans Livreurs.
        $this->get(route('admin.delivery-partners.index'))->assertOk()->assertDontSee('Marins');
        $this->get(route('admin.deliverers.index'))->assertOk()->assertDontSee('Marins');
        $this->get(route('admin.delivery-partners.edit', $partner))->assertRedirect(route('admin.maritime.partners.show', $partner));

        foreach ([['QG Paris', 'FR', 'Paris'], ['QG Douala', 'CM', 'Douala']] as [$name, $country, $city]) {
            $this->post(route('admin.maritime.hubs.store', $partner), ['name' => $name, 'country' => $country, 'city' => $city, 'address' => '1 rue'])->assertSessionHas('success');
        }
        [$paris, $douala] = $partner->maritimeHubs()->orderBy('id')->pluck('id')->all();

        // Tarif du transporteur au m³ : 900 € le m³, 1 m³ = 1 000 kg.
        $this->post(route('admin.maritime.departures.store', $partner), [
            'origin_hub_id' => $paris, 'destination_hub_id' => $douala,
            'departure_date' => today()->addDays(30)->toDateString(),
            'capacity_kg' => 5000, 'pricing_unit' => 'cbm', 'price' => 900, 'currency' => 'eur',
        ])->assertSessionHas('success');
        $departure = MaritimeDeparture::where('deliverer_company_id', $partner->id)->firstOrFail();
        $this->assertTrue($departure->isPricedPerCbm());
        // 500 kg = 0,5 m³ → 450 € de fret partenaire ; 0,90 €/kg, 0,99 €/kg avec 10 % ASSO.
        $this->assertEquals(1000, (float) $departure->kg_per_cbm);
        $this->assertEquals(450, $departure->partnerFreightFor(500));
        $this->assertEquals(0.99, $departure->publicPricePerKg());

        $this->get(route('admin.maritime.partners.show', $partner))->assertOk()->assertSee('900,00 EUR/m³', false);

        // Un QG d'un autre partenaire est refusé.
        $other = $this->departure();
        $this->post(route('admin.maritime.departures.store', $partner), [
            'origin_hub_id' => $other->origin_hub_id, 'destination_hub_id' => $douala,
            'departure_date' => today()->addDays(30)->toDateString(),
            'capacity_kg' => 100, 'pricing_unit' => 'kg', 'price' => 3, 'currency' => 'EUR',
        ])->assertSessionHasErrors('origin_hub_id');
    }

    public function test_booking_on_cbm_departure_converts_kilos(): void
    {
        $this->fakeKpay();
        $departure = $this->departure();
        $departure->update(['price_per_cbm' => 900, 'kg_per_cbm' => 1000]);
        Sanctum::actingAs(User::factory()->create());

        $res = $this->postJson("/api/v1/maritime/departures/{$departure->id}/book", $this->payload())->assertStatus(201);
        // 50 kg = 0,05 m³ × 900 = 45 € ; +10 % = 49,50 ; assurance 2 % × 1000 = 20.
        $this->assertDatabaseHas('maritime_bookings', [
            'id' => $res->json('booking_id'), 'freight_subtotal' => 45, 'commission_amount' => 4.5, 'total_price' => 69.5, 'volume_cbm' => 0.05,
        ]);
        $res->assertJsonPath('data.departure.price_per_cbm', 990);
    }

    public function test_partner_payout_page_uses_platform_withdrawal_principle(): void
    {
        $partner = $this->departure()->company;
        \App\Services\PartnerAccountService::credit($partner->id, 131200, \App\Models\WalletTransaction::label('maritime_freight', ['booking' => 1]), 'maritime_booking', 1);
        // Idempotent.
        \App\Services\PartnerAccountService::credit($partner->id, 131200, \App\Models\WalletTransaction::label('maritime_freight', ['booking' => 1]), 'maritime_booking', 1);
        $account = $partner->fresh()->account;
        $this->assertTrue(\App\Services\PartnerAccountService::isPartnerAccount($account));
        $this->assertEquals(131200, $account->kpayBalanceFor('XAF'));

        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->get(route('admin.partner-payouts.show', $partner))
            ->assertOk()
            ->assertSee('Paiements — '.$partner->name)
            ->assertSee('Mobile Money')
            ->assertSee('Virement bancaire');

        // Gestionnaire : pas d'accès aux paiements.
        $this->actingAs(User::factory()->create(['role' => 'product_manager']));
        $this->get(route('admin.partner-payouts.show', $partner))->assertForbidden();
    }
}
