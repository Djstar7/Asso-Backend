<?php

namespace Tests\Feature;

use App\Models\Currency;
use App\Models\DiaspoBooking;
use App\Models\DiaspoOffer;
use App\Models\ExchangeRate;
use App\Models\ParcelNature;
use App\Models\ServiceConfiguration;
use App\Models\Setting;
use App\Models\User;
use App\Services\FirebaseMessagingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Assurance des colis Diaspo : supplément = max(valeur × taux, minimum),
 * ajouté au prix payé par l'acheteur et crédité à ASSO à la validation du code.
 */
class ParcelInsuranceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        ServiceConfiguration::updateOrCreate(
            ['service_name' => 'kpay'],
            [
                'service_type' => 'payment',
                'is_active' => true,
                'configuration' => ['api_key' => 'k', 'secret_key' => 's', 'base_url' => 'https://kpay.test'],
            ]
        );
        $this->mock(FirebaseMessagingService::class, fn ($m) => $m->shouldReceive('sendToUser')->andReturn([]));

        Currency::firstOrCreate(['code' => 'XAF'], ['name' => 'Franc CFA', 'symbol' => 'FCFA', 'is_active' => true]);
        Currency::firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'symbol' => '€', 'is_active' => true]);
        ExchangeRate::create([
            'from_currency' => 'EUR', 'to_currency' => 'XAF', 'rate' => 656,
            'effective_date' => now()->toDateString(), 'is_active' => true, 'source' => 'test',
        ]);
    }

    private function nature(): ParcelNature
    {
        return ParcelNature::create(['name' => 'Téléphone test', 'insurance_rate' => 5, 'min_insurance_amount' => 2000, 'is_active' => true]);
    }

    public function test_quote_applies_rate_or_minimum(): void
    {
        $nature = $this->nature();
        Sanctum::actingAs(User::factory()->create());

        // 5 % de 100 000 = 5 000 FCFA, au-dessus du minimum.
        $this->postJson('/api/v1/diaspo/insurance-quote', [
            'parcel_nature_id' => $nature->id, 'declared_value' => 100000, 'currency' => 'XAF',
        ])->assertOk()->assertJsonPath('data.insurance_amount', 5000);

        // 5 % de 1 000 = 50 FCFA → minimum de 2 000.
        $this->postJson('/api/v1/diaspo/insurance-quote', [
            'parcel_nature_id' => $nature->id, 'declared_value' => 1000, 'currency' => 'XAF',
        ])->assertOk()->assertJsonPath('data.insurance_amount', 2000);
    }

    public function test_parcel_natures_list_only_active_with_air_hint(): void
    {
        $this->nature();
        ParcelNature::create(['name' => 'Masquée', 'insurance_rate' => 1, 'min_insurance_amount' => 0, 'is_active' => false]);
        Setting::set('diaspo_air_max_kg_hint', 30);
        Sanctum::actingAs(User::factory()->create());

        $res = $this->getJson('/api/v1/diaspo/parcel-natures')->assertOk();
        $names = collect($res->json('data'))->pluck('name');
        $this->assertTrue($names->contains('Téléphone test'));
        $this->assertFalse($names->contains('Masquée'));
        $this->assertEquals(30, $res->json('meta.air_max_kg_hint'));
    }

    public function test_air_booking_adds_insurance_and_credits_it_to_asso(): void
    {
        Http::fake([
            '*/api/v1/payments/init' => Http::response(['id' => 'pay_ins_1', 'status' => 'PENDING'], 200),
            '*/api/v1/payments/*' => Http::response(['status' => 'SUCCESS'], 200),
        ]);
        Setting::set('diaspo_commission_rate', 10);
        $platform = User::factory()->create(['email' => 'platform@asso.test']);
        Setting::set('platform_account_email', 'platform@asso.test');
        $nature = $this->nature();

        $seller = User::factory()->create();
        $buyer = User::factory()->create();
        $offer = DiaspoOffer::create([
            'user_id' => $seller->id, 'status' => 'approved', 'verification_status' => 'verified',
            'departure_country' => 'FR', 'departure_city' => 'Paris', 'departure_datetime' => now()->addDays(5),
            'arrival_country' => 'CM', 'arrival_city' => 'Douala', 'arrival_datetime' => now()->addDays(6),
            'price_per_kg' => 10, 'available_kg' => 20, 'remaining_kg' => 20, 'currency' => 'EUR',
        ]);

        Sanctum::actingAs($buyer);
        $book = $this->postJson("/api/v1/diaspo/offers/{$offer->id}/book", [
            'kg_booked' => 5,
            'payment_method' => 'kpay',
            'provider' => 'MTN_MOMO_CMR',
            'phone_number' => '237653456789',
            'parcel_nature_id' => $nature->id,
            'declared_value' => 500,
        ])->assertStatus(201);
        $id = $book->json('booking_id');

        // Fret 55 € (dont 5 € de commission) + assurance 5 % de 500 € = 25 € → 80 €.
        $this->assertDatabaseHas('diaspo_bookings', ['id' => $id, 'subtotal' => 50, 'commission_amount' => 5, 'insurance_amount' => 25, 'total_price' => 80]);
        $book->assertJsonPath('data.insurance_amount', 25)->assertJsonPath('data.subtotal', 55);

        $this->getJson("/api/v1/diaspo/bookings/{$id}/payment-status")->assertJsonPath('data.status', 'paid');

        // Le voyageur ne voit pas le supplément.
        Sanctum::actingAs($seller);
        $this->getJson("/api/v1/diaspo/bookings/{$id}")
            ->assertJsonPath('data.insurance_amount', 0)
            ->assertJsonPath('data.total_price', 55)
            ->assertJsonPath('data.declared_value', 500);

        $this->postJson("/api/v1/diaspo/bookings/{$id}/seller-confirm-code", [
            'confirmation_code' => DiaspoBooking::find($id)->confirmation_code,
        ])->assertOk();

        // Voyageur : 50 € → 32 800 ; ASSO : commission 3 280 + assurance 16 400.
        $this->assertEquals(32800, $seller->fresh()->kpayBalanceFor('XAF'));
        $this->assertEquals(19680, $platform->fresh()->kpayBalanceFor('XAF'));
        $this->assertDatabaseHas('wallet_transactions', [
            'user_id' => $platform->id, 'amount' => 16400, 'description' => "Assurance colis — Diaspo #{$id}",
        ]);
    }

    public function test_declared_value_entered_in_user_currency_is_converted(): void
    {
        Http::fake([
            '*/api/v1/payments/init' => Http::response(['id' => 'pay_ins_2', 'status' => 'PENDING'], 200),
        ]);
        Setting::set('diaspo_commission_rate', 10);
        $nature = $this->nature();
        $offer = DiaspoOffer::create([
            'user_id' => User::factory()->create()->id, 'status' => 'approved', 'verification_status' => 'verified',
            'departure_country' => 'FR', 'departure_city' => 'Paris', 'departure_datetime' => now()->addDays(5),
            'arrival_country' => 'CM', 'arrival_city' => 'Douala', 'arrival_datetime' => now()->addDays(6),
            'price_per_kg' => 10, 'available_kg' => 20, 'remaining_kg' => 20, 'currency' => 'EUR',
        ]);
        Sanctum::actingAs(User::factory()->create());

        // 328 000 FCFA saisis par l'acheteur = 500 € → 5 % = 25 €.
        $this->postJson('/api/v1/diaspo/insurance-quote', [
            'parcel_nature_id' => $nature->id, 'declared_value' => 328000, 'declared_value_currency' => 'XAF', 'currency' => 'EUR',
        ])->assertOk()->assertJsonPath('data.insurance_amount', 25);

        $id = $this->postJson("/api/v1/diaspo/offers/{$offer->id}/book", [
            'kg_booked' => 5, 'payment_method' => 'kpay', 'provider' => 'MTN_MOMO_CMR', 'phone_number' => '237653456789',
            'parcel_nature_id' => $nature->id, 'declared_value' => 328000, 'declared_value_currency' => 'XAF',
        ])->assertStatus(201)->json('booking_id');

        // Enregistrée dans la devise de l'offre.
        $this->assertDatabaseHas('diaspo_bookings', ['id' => $id, 'declared_value' => 500, 'insurance_amount' => 25]);
    }

    public function test_nature_requires_declared_value(): void
    {
        $nature = $this->nature();
        $offer = DiaspoOffer::create([
            'user_id' => User::factory()->create()->id, 'status' => 'approved', 'verification_status' => 'verified',
            'departure_country' => 'FR', 'departure_city' => 'Paris', 'departure_datetime' => now()->addDays(5),
            'arrival_country' => 'CM', 'arrival_city' => 'Douala', 'arrival_datetime' => now()->addDays(6),
            'price_per_kg' => 10, 'available_kg' => 20, 'remaining_kg' => 20, 'currency' => 'EUR',
        ]);
        Sanctum::actingAs(User::factory()->create());

        $this->postJson("/api/v1/diaspo/offers/{$offer->id}/book", [
            'kg_booked' => 5, 'payment_method' => 'kpay', 'provider' => 'MTN_MOMO_CMR', 'phone_number' => '237653456789',
            'parcel_nature_id' => $nature->id,
        ])->assertStatus(422)->assertJsonValidationErrors('declared_value');
    }

    public function test_admin_manages_natures(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);

        $this->post(route('admin.diaspo.parcel-natures.store'), [
            'name' => 'Montre', 'insurance_rate' => 6, 'min_insurance_amount' => 1500,
            'translations' => ['en' => ['name' => 'Watch']],
        ])->assertRedirect();
        $nature = ParcelNature::where('name', 'Montre')->firstOrFail();
        $this->assertEquals('Watch', $nature->getTranslation('name', 'en'));

        $this->get(route('admin.diaspo.parcel-natures.index'))->assertOk()->assertSee('Montre');
        $this->post(route('admin.diaspo.parcel-natures.settings'), ['air_max_kg_hint' => 25])->assertRedirect();
        $this->assertEquals(25, (float) Setting::get('diaspo_air_max_kg_hint'));
    }
}
