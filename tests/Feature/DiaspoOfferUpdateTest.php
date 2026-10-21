<?php

namespace Tests\Feature;

use App\Models\DiaspoBooking;
use App\Models\DiaspoOffer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DiaspoOfferUpdateTest extends TestCase
{
    use RefreshDatabase;

    private function offerWithBooking(string $status = 'paid'): array
    {
        $seller = User::factory()->create();
        $buyer = User::factory()->create();
        $offer = DiaspoOffer::withoutEvents(fn () => DiaspoOffer::create([
            'user_id' => $seller->id,
            'status' => 'approved', 'verification_status' => 'verified',
            'departure_country' => 'FR', 'departure_city' => 'Paris',
            'arrival_country' => 'CM', 'arrival_city' => 'Douala',
            'departure_datetime' => now()->addDays(5),
            'arrival_datetime' => now()->addDays(6),
            'price_per_kg' => 10, 'currency' => 'EUR',
            'available_kg' => 20, 'remaining_kg' => 15,
        ]));
        $booking = DiaspoBooking::create([
            'diaspo_offer_id' => $offer->id,
            'seller_user_id' => $seller->id, 'buyer_user_id' => $buyer->id,
            'kg_booked' => 5, 'price_per_kg' => 10,
            'subtotal' => 50, 'commission_amount' => 5, 'total_price' => 55,
            'status' => $status, 'payment_status' => 'completed',
            'payment_reference' => 'existing-payment',
        ]);
        Sanctum::actingAs($seller);

        return [$offer, $booking];
    }

    public function test_owner_can_reschedule_with_existing_bookings_without_changing_their_amounts(): void
    {
        foreach (['paid', 'confirmed', 'completed'] as $status) {
            [$offer, $booking] = $this->offerWithBooking($status);
            $bookingBefore = $booking->fresh()->getAttributes();
            $departure = now()->addDays(10)->startOfMinute();
            $arrival = now()->addDays(11)->startOfMinute();

            $this->putJson("/api/v1/diaspo/offers/{$offer->id}", [
                'departure_datetime' => $departure->toIso8601String(),
                'arrival_datetime' => $arrival->toIso8601String(),
                'price_per_kg' => 12, 'available_kg' => 25,
            ])->assertOk()->assertJsonPath('success', true);

            $offer->refresh();
            $this->assertTrue($offer->departure_datetime->equalTo($departure));
            $this->assertTrue($offer->arrival_datetime->equalTo($arrival));
            $this->assertEquals(20, $offer->remaining_kg);
            $this->assertEquals(12, $offer->price_per_kg);
            $this->assertSame($bookingBefore, $booking->fresh()->getAttributes());
        }
    }

    public function test_capacity_cannot_drop_below_committed_kilos_and_dates_are_not_partially_saved(): void
    {
        [$offer] = $this->offerWithBooking();
        $originalDeparture = $offer->departure_datetime->copy();

        $this->putJson("/api/v1/diaspo/offers/{$offer->id}", [
            'available_kg' => 4,
            'departure_datetime' => now()->addDays(10)->toIso8601String(),
            'arrival_datetime' => now()->addDays(11)->toIso8601String(),
        ])->assertUnprocessable()->assertJsonValidationErrors('available_kg');

        $this->assertEquals(15, $offer->fresh()->remaining_kg);
        $this->assertTrue($offer->fresh()->departure_datetime->equalTo($originalDeparture));
    }

    public function test_capacity_can_be_reduced_to_committed_kilos(): void
    {
        [$offer] = $this->offerWithBooking();
        $this->putJson("/api/v1/diaspo/offers/{$offer->id}", [
            'available_kg' => 5,
        ])->assertOk();
        $this->assertEquals(0, $offer->fresh()->remaining_kg);
    }

    public function test_full_offer_reappears_in_catalogue_when_owner_adds_capacity(): void
    {
        [$offer, $booking] = $this->offerWithBooking();
        $offer->update(['available_kg' => 5, 'remaining_kg' => 0]);
        $this->getJson('/api/v1/diaspo/offers')->assertOk()
            ->assertJsonCount(0, 'data.data');

        $this->putJson("/api/v1/diaspo/offers/{$offer->id}", [
            'available_kg' => 10,
        ])->assertOk();

        $this->getJson('/api/v1/diaspo/offers')->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $offer->id)
            ->assertJsonPath('data.data.0.is_published', true);
        $this->assertEquals(5, $offer->fresh()->remaining_kg);
        $this->assertEquals(5, $booking->fresh()->kg_booked);
        $this->assertSame('paid', $booking->fresh()->status);
    }

    public function test_arrival_must_follow_departure_even_when_only_one_date_changes(): void
    {
        [$offer] = $this->offerWithBooking();
        $this->putJson("/api/v1/diaspo/offers/{$offer->id}", [
            'departure_datetime' => now()->addDays(10)->toIso8601String(),
        ])->assertUnprocessable()->assertJsonValidationErrors('arrival_datetime');

        $this->putJson("/api/v1/diaspo/offers/{$offer->id}", [
            'arrival_datetime' => $offer->departure_datetime->toIso8601String(),
        ])->assertUnprocessable()->assertJsonValidationErrors('arrival_datetime');
    }

    public function test_expired_offer_can_be_rescheduled_and_published_again(): void
    {
        [$offer] = $this->offerWithBooking();
        $offer->update([
            'departure_datetime' => now()->subDays(2),
            'arrival_datetime' => now()->subDay(),
        ]);
        $this->assertSame('expired', $offer->fresh()->status);

        $this->putJson("/api/v1/diaspo/offers/{$offer->id}", [
            'departure_datetime' => now()->addDays(10)->toIso8601String(),
            'arrival_datetime' => now()->addDays(11)->toIso8601String(),
        ])->assertOk();
        $this->assertSame('approved', $offer->fresh()->status);
        $this->assertTrue($offer->fresh()->is_published);
    }

    public function test_another_user_cannot_edit_the_offer(): void
    {
        [$offer] = $this->offerWithBooking();
        Sanctum::actingAs(User::factory()->create());
        $this->putJson("/api/v1/diaspo/offers/{$offer->id}", [
            'available_kg' => 25,
        ])->assertForbidden();
        $this->assertEquals(20, $offer->fresh()->available_kg);
    }

    public function test_completed_offer_remains_closed(): void
    {
        [$offer] = $this->offerWithBooking();
        $offer->update(['status' => 'completed']);
        $this->putJson("/api/v1/diaspo/offers/{$offer->id}", [
            'available_kg' => 25,
        ])->assertStatus(400);
        $this->assertEquals(20, $offer->fresh()->available_kg);
    }
}
