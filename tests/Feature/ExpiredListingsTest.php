<?php

namespace Tests\Feature;

use App\Models\DiaspoBooking;
use App\Models\DiaspoOffer;
use App\Models\Notification;
use App\Models\NotificationDigestItem;
use App\Models\User;
use App\Services\FirebaseMessagingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Nettoyage des annonces : offres Diaspo passées (une offre réservée n'est jamais
 * supprimée) et annonces de la plateforme retirées passé 14 jours.
 */
class ExpiredListingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(FirebaseMessagingService::class, fn ($m) => $m->shouldReceive('sendToTopicsLocalized', 'sendToUser')->andReturn([]));
    }

    private function offer(string $departure = '-2 days', ?User $seller = null): DiaspoOffer
    {
        $offer = DiaspoOffer::create([
            'user_id' => ($seller ?? User::factory()->create())->id,
            'departure_country' => 'FR', 'departure_city' => 'Paris', 'departure_datetime' => now()->addDays(5),
            'arrival_country' => 'CM', 'arrival_city' => 'Douala', 'arrival_datetime' => now()->addDays(6),
            'price_per_kg' => 10, 'available_kg' => 20, 'remaining_kg' => 20, 'currency' => 'EUR',
        ]);
        $offer->forceFill([
            'status' => 'approved', 'verification_status' => 'verified',
            'departure_datetime' => now()->modify($departure),
            'arrival_datetime' => now()->modify($departure)->addDay(),
        ])->saveQuietly();

        return $offer;
    }

    private function bookingOn(DiaspoOffer $offer, string $status): DiaspoBooking
    {
        return DiaspoBooking::create([
            'diaspo_offer_id' => $offer->id,
            'seller_user_id' => $offer->user_id, 'buyer_user_id' => User::factory()->create()->id,
            'kg_booked' => 5, 'price_per_kg' => 10, 'subtotal' => 50, 'commission_amount' => 5, 'total_price' => 55,
            'status' => $status, 'payment_status' => 'completed', 'payment_reference' => uniqid('ref-'),
        ]);
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->forceFill(['role' => 'admin'])->saveQuietly();

        return $admin;
    }

    public function test_past_offer_without_booking_is_removed_the_day_after_departure(): void
    {
        $past = $this->offer('-2 days');
        $today = $this->offer('today');
        $future = $this->offer('+3 days');

        $this->artisan('diaspo:purge-expired')->assertSuccessful();

        $this->assertSoftDeleted('diaspo_offers', ['id' => $past->id, 'removal_reason' => 'Départ passé sans réservation']);
        $this->assertNotSoftDeleted('diaspo_offers', ['id' => $today->id]);
        $this->assertNotSoftDeleted('diaspo_offers', ['id' => $future->id]);
        $this->assertSame('approved', $future->fresh()->status);
    }

    public function test_past_offer_with_a_paid_booking_is_kept_and_its_booking_untouched(): void
    {
        $offer = $this->offer('-2 days');
        $booking = $this->bookingOn($offer, 'paid');

        $this->artisan('diaspo:purge-expired')->assertSuccessful();

        $this->assertNotSoftDeleted('diaspo_offers', ['id' => $offer->id]);
        $this->assertSame('expired', $offer->fresh()->status);
        $this->assertSame('paid', $booking->fresh()->status);
    }

    public function test_past_offer_cannot_be_booked(): void
    {
        $offer = $this->offer('-1 hour');

        Sanctum::actingAs(User::factory()->create());
        $this->postJson("/api/v1/diaspo/offers/{$offer->id}/book", ['kg_booked' => 1, 'payment_method' => 'wallet'])
            ->assertStatus(422);

        $this->assertSame(0, DiaspoBooking::count());
    }

    public function test_admin_cannot_delete_an_offer_with_a_paid_or_confirmed_booking(): void
    {
        foreach (['paid', 'confirmed'] as $status) {
            $offer = $this->offer('+3 days');
            $booking = $this->bookingOn($offer, $status);

            $this->actingAs($this->admin())->delete(route('admin.diaspo.offers.destroy', $offer->id))
                ->assertSessionHas('error');

            $this->assertNotSoftDeleted('diaspo_offers', ['id' => $offer->id]);
            $this->assertSame($status, $booking->fresh()->status);
        }

        $free = $this->offer('+3 days');
        $this->actingAs($this->admin())->delete(route('admin.diaspo.offers.destroy', $free->id))
            ->assertSessionHas('success');
        $this->assertSoftDeleted('diaspo_offers', ['id' => $free->id]);
    }

    public function test_traveller_cannot_delete_an_offer_with_a_confirmed_booking(): void
    {
        $seller = User::factory()->create();
        $offer = $this->offer('+3 days', $seller);
        $this->bookingOn($offer, 'confirmed');

        Sanctum::actingAs($seller);
        $this->deleteJson("/api/v1/diaspo/offers/{$offer->id}")->assertStatus(400);

        $this->assertNotSoftDeleted('diaspo_offers', ['id' => $offer->id]);
    }

    public function test_platform_announcements_leave_after_14_days_and_other_notifications_stay(): void
    {
        $user = User::factory()->create();
        $old = now()->subDays(15);
        $recent = now()->subDays(13);

        foreach ([
            'announcement' => $old, 'new_product' => $old, 'new_real_estate_listing' => $old, 'real_estate_alert' => $old,
            'marketing_digest' => $old, 'order_update' => $old, 'real_estate_listing_published' => $old,
        ] as $type => $date) {
            $n = Notification::create(['user_id' => $user->id, 'type' => $type, 'title' => 'T', 'body' => 'B']);
            $n->forceFill(['created_at' => $date])->saveQuietly();
        }
        $kept = Notification::create(['user_id' => $user->id, 'type' => 'new_product', 'title' => 'T', 'body' => 'B']);
        $kept->forceFill(['created_at' => $recent])->saveQuietly();

        $oldItem = NotificationDigestItem::create(['event_key' => 'old', 'texts' => ['fr' => ['T', 'B']], 'data' => ['type' => 'new_product']]);
        $oldItem->forceFill(['created_at' => $old])->saveQuietly();
        $newItem = NotificationDigestItem::create(['event_key' => 'new', 'texts' => ['fr' => ['T', 'B']], 'data' => ['type' => 'new_product']]);
        $newItem->forceFill(['created_at' => $recent])->saveQuietly();

        $this->artisan('announcements:purge')->assertSuccessful();

        $this->assertEqualsCanonicalizing(
            ['order_update', 'real_estate_listing_published', 'new_product'],
            Notification::pluck('type')->all()
        );
        $this->assertSame([$newItem->id], NotificationDigestItem::pluck('id')->all());
    }

    public function test_digest_hides_a_diaspo_offer_that_already_left(): void
    {
        $this->freezeTime();
        $user = User::factory()->create();
        $date = now(config('notifications.digest_timezone'))->toDateString();
        Notification::create(['user_id' => $user->id, 'type' => 'marketing_digest', 'title' => 'T', 'body' => 'B', 'data' => ['digest_date' => $date]]);
        $user->forceFill(['created_at' => now()->subDay()])->saveQuietly();

        foreach (['gone' => now()->subHour(), 'open' => now()->addDay()] as $key => $departure) {
            NotificationDigestItem::create(['event_key' => $key, 'texts' => ['fr' => [$key, 'B']], 'data' => ['type' => 'new_diaspo_offer', 'departure_datetime' => $departure->toIso8601String()]]);
        }

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/notifications/digests/'.$date)
            ->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('items.0.title', 'open');
    }
}
