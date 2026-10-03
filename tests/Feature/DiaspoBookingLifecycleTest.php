<?php

namespace Tests\Feature;

use App\Models\Currency;
use App\Models\DiaspoBooking;
use App\Models\DiaspoOffer;
use App\Models\ExchangeRate;
use App\Models\ServiceConfiguration;
use App\Models\Setting;
use App\Models\User;
use App\Services\FirebaseMessagingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

/**
 * Cycle de vie E2E d'une réservation Diaspo, piloté par les VRAIES routes HTTP
 * (/api/v1/diaspo/...). Complète DiaspoBookingConsolidationTest (qui teste les
 * services en direct) en couvrant le routage — le point de risque après la fusion
 * unify où deux implémentations Diaspo cohabitent — et le cycle complet :
 *
 *   book (PayIn KPay) → payment-status (confirmation) → seller-confirm-code (règlement).
 *
 * KPay est instancié en dur (`new KPayService()`) dans le contrôleur : on le neutralise
 * via une ServiceConfiguration kpay (pour isConfigured()) + Http::fake() sur son API.
 */
class DiaspoBookingLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private const KPAY_HOST = 'https://kpay.test';

    protected function setUp(): void
    {
        parent::setUp();

        // getConfig('kpay') est mis en cache — repartir propre à chaque test.
        Cache::flush();
        ServiceConfiguration::updateOrCreate(
            ['service_name' => 'kpay'],
            [
                'service_type' => 'payment',
                'is_active' => true,
                'configuration' => [
                    'api_key' => 'kpay_test_key',
                    'secret_key' => 'kpay_test_secret',
                    'base_url' => self::KPAY_HOST,
                ],
            ]
        );

        $this->mock(FirebaseMessagingService::class, function ($m) {
            $m->shouldReceive('sendToUser')->andReturn([]);
        });

        // Les offres sont en EUR : sans taux stocké, le minimum KPay (en XAF)
        // était comparé au montant en euros et la réservation refusée.
        $this->storeEurRate();
    }

    /** Offre publiée et réservable (statut unifié 'approved', voyageur vérifié). */
    private function makeApprovedOffer(User $seller, float $availableKg = 20, float $pricePerKg = 10): DiaspoOffer
    {
        return DiaspoOffer::create([
            'user_id' => $seller->id,
            'status' => 'approved',
            'verification_status' => 'verified',
            'departure_country' => 'FR', 'departure_city' => 'Paris', 'departure_datetime' => now()->addDays(5),
            'arrival_country' => 'CM', 'arrival_city' => 'Douala', 'arrival_datetime' => now()->addDays(6),
            'price_per_kg' => $pricePerKg, 'available_kg' => $availableKg, 'remaining_kg' => $availableKg,
            'currency' => 'EUR',
        ]);
    }

    public function test_full_lifecycle_book_pay_validate_code(): void
    {
        Http::fake([
            '*/api/v1/payments/init' => Http::response(['id' => 'pay_e2e_1', 'status' => 'PENDING'], 200),
            '*/api/v1/payments/*' => Http::response(['status' => 'SUCCESS'], 200), // polling → payé
        ]);

        // Le taux vient d'un réglage administrable : on le fige pour que le
        // test ne dépende pas de la configuration de l'environnement.
        Setting::set('diaspo_commission_rate', 10);
        $platform = $this->makePlatformAccount();

        $seller = User::factory()->create(['pending_earnings' => 0]);
        $buyer = User::factory()->create();
        $offer = $this->makeApprovedOffer($seller);

        // 1) L'acheteur réserve 5 kg → PayIn KPay initié
        Sanctum::actingAs($buyer);
        $book = $this->postJson("/api/v1/diaspo/offers/{$offer->id}/book", [
            'kg_booked' => 5,
            'payment_method' => 'kpay',
            'provider' => 'MTN_MOMO_CMR',
            'phone_number' => '237653456789',
        ]);
        $book->assertStatus(201)->assertJsonPath('success', true);
        $bookingId = $book->json('booking_id');

        $this->assertDatabaseHas('diaspo_bookings', [
            'id' => $bookingId,
            'status' => 'pending',
            'payment_status' => 'pending',
            'payment_reference' => 'pay_e2e_1',
        ]);
        // kg réservés : 20 - 5 = 15
        $this->assertEquals(15, (float) $offer->fresh()->remaining_kg);

        // subtotal = 50, commission 10% = 5, total = 55
        $this->assertDatabaseHas('diaspo_bookings', ['id' => $bookingId, 'subtotal' => 50, 'total_price' => 55]);

        // 2) Polling du statut → KPay répond SUCCESS → réservation payée
        $status = $this->getJson("/api/v1/diaspo/bookings/{$bookingId}/payment-status");
        $status->assertOk()
            ->assertJsonPath('data.payment_status', 'completed')
            ->assertJsonPath('data.status', 'paid');

        // 3) L'acheteur ne peut pas débloquer les fonds avant la validation du code.
        Sanctum::actingAs($buyer);
        $this->postJson("/api/v1/diaspo/bookings/{$bookingId}/confirm-receipt")->assertStatus(422);
        $this->assertEquals(0, $seller->fresh()->kpayBalanceFor('XAF'));
        $this->assertEquals(0, $platform->fresh()->kpayBalanceFor('XAF'));

        // 4) Le voyageur valide le code remis à la livraison → clôture et règlement
        $code = \App\Models\DiaspoBooking::find($bookingId)->confirmation_code;
        Sanctum::actingAs($seller);
        $this->postJson("/api/v1/diaspo/bookings/{$bookingId}/seller-confirm-code", [
            'confirmation_code' => $code,
        ])->assertOk()->assertJsonPath('data.status', 'completed');

        // Wallet (XAF, 1 EUR = 656 XAF) : le voyageur reçoit son sous-total
        // (50 € → 32 800), ASSO la commission (5 € → 3 280).
        $this->assertEquals(32800, $seller->fresh()->kpayBalanceFor('XAF'));
        $this->assertEquals(3280, $platform->fresh()->kpayBalanceFor('XAF'));
        $this->assertEquals(0, (float) $seller->fresh()->pending_earnings);
        $this->assertDatabaseHas('wallet_transactions', [
            'user_id' => $platform->id,
            'type' => 'credit',
            'amount' => 3280,
            'description' => "Commission ASSO — Réservation Diaspo #{$bookingId}",
        ]);

        // Une seconde validation, ou une confirmation de l'acheteur, ne crédite rien de plus.
        $this->postJson("/api/v1/diaspo/bookings/{$bookingId}/seller-confirm-code", [
            'confirmation_code' => $code,
        ])->assertStatus(422);
        Sanctum::actingAs($buyer);
        $this->postJson("/api/v1/diaspo/bookings/{$bookingId}/confirm-receipt")->assertStatus(422);
        $this->assertEquals(32800, $seller->fresh()->kpayBalanceFor('XAF'));
        $this->assertEquals(3280, $platform->fresh()->kpayBalanceFor('XAF'));
    }

    /** Code validé avant le correctif : la confirmation de l'acheteur règle encore la course. */
    public function test_confirm_receipt_settles_a_booking_whose_code_was_already_validated(): void
    {
        $platform = $this->makePlatformAccount();
        [$seller, $buyer, $booking] = $this->makePaidBooking('confirmed');

        Sanctum::actingAs($buyer);
        $this->postJson("/api/v1/diaspo/bookings/{$booking->id}/confirm-receipt")
            ->assertOk()->assertJsonPath('data.status', 'completed');

        $this->assertEquals(32800, $seller->fresh()->kpayBalanceFor('XAF'));
        $this->assertEquals(3280, $platform->fresh()->kpayBalanceFor('XAF'));
    }

    public function test_buyer_cannot_cancel_once_the_code_is_validated(): void
    {
        [, $buyer, $booking] = $this->makePaidBooking('confirmed');

        Sanctum::actingAs($buyer);
        $this->postJson("/api/v1/diaspo/bookings/{$booking->id}/cancel")->assertStatus(422);

        $this->assertEquals('confirmed', $booking->fresh()->status);
        $this->assertEquals(0, $buyer->fresh()->kpayBalanceFor('EUR'));
    }

    public function test_code_validation_without_exchange_rate_credits_nothing(): void
    {
        ExchangeRate::query()->delete();
        $platform = $this->makePlatformAccount();
        [$seller, , $booking] = $this->makePaidBooking('paid');

        Sanctum::actingAs($seller);
        $this->postJson("/api/v1/diaspo/bookings/{$booking->id}/seller-confirm-code", [
            'confirmation_code' => '123456',
        ])->assertStatus(503)->assertJsonPath('success', false);

        $this->assertEquals('paid', $booking->fresh()->status);
        $this->assertEquals(0, $seller->fresh()->kpayBalanceFor('XAF'));
        $this->assertEquals(0, $platform->fresh()->kpayBalanceFor('XAF'));
    }

    /** Réservation payée (50 € + 5 € de commission), au statut voulu. */
    private function makePaidBooking(string $status): array
    {
        $seller = User::factory()->create();
        $buyer = User::factory()->create();
        $offer = $this->makeApprovedOffer($seller);
        $booking = DiaspoBooking::create([
            'diaspo_offer_id' => $offer->id,
            'buyer_user_id' => $buyer->id,
            'seller_user_id' => $seller->id,
            'kg_booked' => 5,
            'price_per_kg' => 10,
            'subtotal' => 50,
            'commission_amount' => 5,
            'total_price' => 55,
            'status' => $status,
            'payment_status' => 'completed',
            'payment_method' => 'kpay',
            'confirmation_code' => '123456',
        ]);

        return [$seller, $buyer, $booking];
    }

    private function makePlatformAccount(): User
    {
        $platform = User::factory()->create(['email' => 'platform@asso.test']);
        Setting::set('platform_account_email', 'platform@asso.test');

        return $platform;
    }

    private function storeEurRate(): void
    {
        Currency::firstOrCreate(['code' => 'XAF'], ['name' => 'Franc CFA', 'symbol' => 'FCFA', 'is_active' => true]);
        Currency::firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'symbol' => '€', 'is_active' => true]);

        ExchangeRate::create([
            'from_currency' => 'EUR',
            'to_currency' => 'XAF',
            'rate' => 656,
            'effective_date' => now()->toDateString(),
            'is_active' => true,
            'source' => 'test',
        ]);
    }

    public function test_payment_failure_cancels_booking_and_restores_kg(): void
    {
        Http::fake([
            '*/api/v1/payments/init' => Http::response(['id' => 'pay_e2e_fail', 'status' => 'PENDING'], 200),
            '*/api/v1/payments/*' => Http::response(['status' => 'FAILED'], 200),
        ]);

        $seller = User::factory()->create();
        $buyer = User::factory()->create();
        $offer = $this->makeApprovedOffer($seller);

        Sanctum::actingAs($buyer);
        $bookingId = $this->postJson("/api/v1/diaspo/offers/{$offer->id}/book", [
            'kg_booked' => 5, 'payment_method' => 'kpay', 'provider' => 'MTN_MOMO_CMR', 'phone_number' => '237653456029',
        ])->assertStatus(201)->json('booking_id');

        $this->assertEquals(15, (float) $offer->fresh()->remaining_kg);

        // Polling → FAILED → annulation + libération des kg
        $this->getJson("/api/v1/diaspo/bookings/{$bookingId}/payment-status")
            ->assertOk()->assertJsonPath('data.status', 'cancelled');

        // kg restaurés : 15 + 5 = 20
        $this->assertEquals(20, (float) $offer->fresh()->remaining_kg);
    }

    public function test_cannot_book_own_offer(): void
    {
        Http::fake(['*' => Http::response(['id' => 'x', 'status' => 'PENDING'], 200)]);

        $seller = User::factory()->create();
        $offer = $this->makeApprovedOffer($seller);

        Sanctum::actingAs($seller);
        $this->postJson("/api/v1/diaspo/offers/{$offer->id}/book", [
            'kg_booked' => 5, 'payment_method' => 'kpay', 'provider' => 'MTN_MOMO_CMR', 'phone_number' => '237653456789',
        ])->assertStatus(422);

        $this->assertDatabaseCount('diaspo_bookings', 0);
    }

    public function test_seller_confirm_code_rejects_wrong_code(): void
    {
        Http::fake([
            '*/api/v1/payments/init' => Http::response(['id' => 'pay_wc', 'status' => 'PENDING'], 200),
            '*/api/v1/payments/*' => Http::response(['status' => 'SUCCESS'], 200),
        ]);

        $seller = User::factory()->create();
        $buyer = User::factory()->create();
        $offer = $this->makeApprovedOffer($seller);

        Sanctum::actingAs($buyer);
        $bookingId = $this->postJson("/api/v1/diaspo/offers/{$offer->id}/book", [
            'kg_booked' => 5, 'payment_method' => 'kpay', 'provider' => 'MTN_MOMO_CMR', 'phone_number' => '237653456789',
        ])->json('booking_id');
        $this->getJson("/api/v1/diaspo/bookings/{$bookingId}/payment-status")->assertOk();

        Sanctum::actingAs($seller);
        $this->postJson("/api/v1/diaspo/bookings/{$bookingId}/seller-confirm-code", [
            'confirmation_code' => '000000',
        ])->assertStatus(422);
    }

    /**
     * Annuler une réservation PAYÉE doit rembourser l'acheteur et rendre les kg.
     *
     * La devise vit sur l'offre, pas sur la réservation : le remboursement
     * échouait auparavant sur une devise nulle.
     */
    public function test_cancelling_a_paid_booking_refunds_the_buyer_and_restores_kg(): void
    {
        Http::fake([
            '*/api/v1/payments/init' => Http::response(['id' => 'pay_cancel', 'status' => 'PENDING'], 200),
            '*/api/v1/payments/*' => Http::response(['status' => 'SUCCESS'], 200),
        ]);

        $seller = User::factory()->create();
        $buyer = User::factory()->create();
        $offer = $this->makeApprovedOffer($seller, availableKg: 20, pricePerKg: 10);

        Sanctum::actingAs($buyer);
        $bookingId = $this->postJson("/api/v1/diaspo/offers/{$offer->id}/book", [
            'kg_booked' => 5,
            'payment_method' => 'kpay',
            'provider' => 'MTN_MOMO_CMR',
            'phone_number' => '237653456789',
        ])->json('booking_id');

        $this->getJson("/api/v1/diaspo/bookings/{$bookingId}/payment-status")->assertOk();

        $booking = DiaspoBooking::findOrFail($bookingId);
        $this->assertSame('completed', $booking->payment_status);
        $this->assertSame(15.0, (float) $offer->fresh()->remaining_kg);

        $this->postJson("/api/v1/diaspo/bookings/{$bookingId}/cancel", [
            'cancel_reason' => 'Changement de programme',
        ])->assertOk()->assertJsonPath('success', true);

        $booking->refresh();
        $this->assertSame('cancelled', $booking->status);
        $this->assertSame('refunded', $booking->payment_status);
        $this->assertNotNull($booking->refunded_at);

        // Les kg repartent à l'offre.
        $this->assertSame(20.0, (float) $offer->fresh()->remaining_kg);

        // L'acheteur est remboursé du total payé, dans la devise de l'offre.
        $this->assertDatabaseHas('wallet_balances', [
            'user_id' => $buyer->id,
            'currency' => $offer->currency,
            'balance' => $booking->total_price,
        ]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
