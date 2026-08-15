<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\DiaspoController;
use App\Models\DiaspoBooking;
use App\Models\DiaspoOffer;
use App\Models\User;
use App\Services\FirebaseMessagingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Consolidation Diaspo sur le flux de paiement KPay (DiaspoController) + schéma unifié.
 * Valide que les valeurs d'enum écrites sont acceptées par le schéma et que toApi() marche.
 */
class DiaspoBookingConsolidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(FirebaseMessagingService::class, function ($m) {
            $m->shouldReceive('sendToUser')->andReturn([]);
        });
    }

    private function makeBooking(float $kg = 5, string $paymentStatus = 'pending'): DiaspoBooking
    {
        $seller = User::factory()->create();
        $buyer = User::factory()->create();
        $offer = DiaspoOffer::create([
            'user_id' => $seller->id,
            'status' => 'approved',
            'verification_status' => 'verified',
            'departure_country' => 'FR', 'departure_city' => 'Paris', 'departure_datetime' => now()->addDays(5),
            'arrival_country' => 'CM', 'arrival_city' => 'Douala', 'arrival_datetime' => now()->addDays(6),
            'price_per_kg' => 10, 'available_kg' => 20, 'remaining_kg' => 20 - $kg, 'currency' => 'EUR',
        ]);
        return DiaspoBooking::create([
            'diaspo_offer_id' => $offer->id,
            'buyer_user_id' => $buyer->id,
            'seller_user_id' => $seller->id,
            'kg_booked' => $kg, 'price_per_kg' => 10, 'subtotal' => $kg * 10,
            'commission_amount' => $kg, 'total_price' => $kg * 10 + $kg,
            'status' => 'pending', 'confirmation_code' => '123456',
            'payment_status' => $paymentStatus, 'payment_reference' => 'pay_x',
        ]);
    }

    public function test_confirm_sets_valid_enums_and_toapi_works(): void
    {
        $booking = $this->makeBooking();
        app(DiaspoController::class)->confirmBookingPayment($booking);

        $booking->refresh();
        $this->assertSame('completed', $booking->payment_status);
        $this->assertSame('paid', $booking->status);

        // toApi ne doit pas planter et expose la devise dérivée de l'offre
        $api = $booking->load(['offer', 'buyer', 'seller'])->toApi();
        $this->assertSame('EUR', $api['currency']);
        $this->assertSame('paid', $api['status']);
        $this->assertArrayHasKey('diaspo_offer', $api);
    }

    public function test_confirm_is_idempotent(): void
    {
        $booking = $this->makeBooking();
        $svc = app(DiaspoController::class);
        $svc->confirmBookingPayment($booking);
        $svc->confirmBookingPayment($booking->fresh());
        $this->assertSame('completed', $booking->fresh()->payment_status);
    }

    public function test_fail_cancels_and_restores_kg(): void
    {
        $booking = $this->makeBooking(5); // offre remaining_kg = 15
        app(DiaspoController::class)->failBookingPayment($booking);

        $booking->refresh();
        $this->assertSame('cancelled', $booking->status);
        // kg restaurés : 15 + 5 = 20
        $this->assertEquals(20, (float) $booking->offer->remaining_kg);
    }

    public function test_fail_is_idempotent_no_double_kg_restore(): void
    {
        $booking = $this->makeBooking(5);
        $svc = app(DiaspoController::class);
        $svc->failBookingPayment($booking);
        $svc->failBookingPayment($booking->fresh());
        $this->assertEquals(20, (float) $booking->fresh()->offer->remaining_kg);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
