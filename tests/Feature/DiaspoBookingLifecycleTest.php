<?php

namespace Tests\Feature;

use App\Models\DiaspoOffer;
use App\Models\ServiceConfiguration;
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
 *   book (PayIn KPay) → payment-status (confirmation) → seller-confirm-code → confirm-receipt.
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

    public function test_full_lifecycle_book_pay_confirm_receipt(): void
    {
        Http::fake([
            '*/api/v1/payments/init' => Http::response(['id' => 'pay_e2e_1', 'status' => 'PENDING'], 200),
            '*/api/v1/payments/*' => Http::response(['status' => 'SUCCESS'], 200), // polling → payé
        ]);

        $seller = User::factory()->create(['pending_earnings' => 0]);
        $buyer = User::factory()->create();
        $offer = $this->makeApprovedOffer($seller);

        // 1) L'acheteur réserve 5 kg → PayIn KPay initié
        Sanctum::actingAs($buyer);
        $book = $this->postJson("/api/v1/diaspo/offers/{$offer->id}/book", [
            'kg_booked' => 5,
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

        // 3) Le voyageur valide le code de confirmation de l'acheteur
        $code = \App\Models\DiaspoBooking::find($bookingId)->confirmation_code;
        Sanctum::actingAs($seller);
        $this->postJson("/api/v1/diaspo/bookings/{$bookingId}/seller-confirm-code", [
            'confirmation_code' => $code,
        ])->assertOk()->assertJsonPath('data.status', 'confirmed');

        // 4) L'acheteur confirme la réception → clôture + crédit du voyageur (hors commission)
        Sanctum::actingAs($buyer);
        $this->postJson("/api/v1/diaspo/bookings/{$bookingId}/confirm-receipt")
            ->assertOk()->assertJsonPath('data.status', 'completed');

        // Le voyageur est crédité du subtotal (50), la plateforme garde la commission (5)
        $this->assertEquals(50, (float) $seller->fresh()->pending_earnings);
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
            'kg_booked' => 5, 'provider' => 'MTN_MOMO_CMR', 'phone_number' => '237653456029',
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
            'kg_booked' => 5, 'provider' => 'MTN_MOMO_CMR', 'phone_number' => '237653456789',
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
            'kg_booked' => 5, 'provider' => 'MTN_MOMO_CMR', 'phone_number' => '237653456789',
        ])->json('booking_id');
        $this->getJson("/api/v1/diaspo/bookings/{$bookingId}/payment-status")->assertOk();

        Sanctum::actingAs($seller);
        $this->postJson("/api/v1/diaspo/bookings/{$bookingId}/seller-confirm-code", [
            'confirmation_code' => '000000',
        ])->assertStatus(422);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
