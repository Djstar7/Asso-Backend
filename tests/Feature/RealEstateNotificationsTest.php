<?php

namespace Tests\Feature;

use App\Models\DeviceToken;
use App\Models\Notification;
use App\Models\RealEstate\Alert;
use App\Models\RealEstate\Listing;
use App\Models\User;
use App\Services\FirebaseMessagingService;
use App\Services\RealEstate\RealEstateBroadcastService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RealEstateNotificationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function listing(string $type = 'land'): Listing
    {
        return Listing::withoutEvents(fn () => Listing::create([
            'reference' => Listing::newReference($type),
            'type' => $type, 'title' => 'Terrain GWODA', 'city' => 'Yaoundé',
            'status' => 'published', 'net_price' => 40000000, 'published_at' => now(),
        ]));
    }

    private function alert(): Alert
    {
        $alert = Alert::create([
            'user_id' => User::factory()->create()->id, 'type' => 'land',
            'city' => 'Yaoundé', 'budget_max' => 44000000, 'is_active' => true,
        ]);
        $this->travel(1)->minutes();

        return $alert;
    }

    public function test_phpunit_cannot_initialize_a_real_firebase_client(): void
    {
        $client = new \ReflectionProperty(FirebaseMessagingService::class, 'messaging');
        $this->assertNull($client->getValue(new FirebaseMessagingService));
    }

    public function test_publication_retries_only_failed_topics_and_is_idempotent(): void
    {
        $topics = [];
        $fail = true;
        $this->mock(FirebaseMessagingService::class, function ($mock) use (&$topics, &$fail) {
            $mock->shouldReceive('sendToTopic')->andReturnUsing(function ($topic, $title, $body, $data) use (&$topics, &$fail) {
                $topics[] = $topic;
                $this->assertSame('open_real_estate', $data['action']);
                $this->assertSame('land', $data['property_type']);
                $this->assertContainsOnly('string', $data);
                $this->assertStringContainsString('Terrain GWODA', $body);
                $this->assertSame($topic === 'all_users_en' ? 'New property for sale' : 'Nouveau bien à vendre', $title);

                return ['success' => ! ($fail && $topic === 'all_users_en')];
            });
        });
        $listing = $this->listing();
        $service = app(RealEstateBroadcastService::class);
        $service->send($listing->id);
        $this->assertNull($listing->fresh()->announced_at);
        $this->assertSame(['all_users', 'all_users_fr'], $listing->fresh()->announced_topics);
        $fail = false;
        $this->artisan('immo:notify-publications')->assertSuccessful();
        $this->artisan('immo:notify-publications')->assertSuccessful();
        $this->assertNotNull($listing->fresh()->announced_at);
        $this->assertSame(['all_users', 'all_users_fr', 'all_users_en', 'all_users_en'], $topics);
    }

    public function test_archived_or_deleted_listing_is_not_announced_on_retry(): void
    {
        $this->mock(FirebaseMessagingService::class)->shouldNotReceive('sendToTopic');
        $listing = $this->listing();
        $listing->update(['status' => 'archived']);
        app(RealEstateBroadcastService::class)->send($listing->id);
        $listing->delete();
        $this->artisan('immo:notify-publications')->assertSuccessful();
        $this->assertNull($listing->fresh()->announced_at);
    }

    public function test_land_alert_excludes_rentals_and_respects_public_budget(): void
    {
        $alert = $this->alert();
        $this->listing();
        $this->listing('rent');
        $this->listing()->update(['net_price' => 50000000]);
        $this->travel(1)->seconds();
        $this->mock(FirebaseMessagingService::class)->shouldReceive('sendToUser')->once()
            ->withArgs(function ($user, $title, $body, $data) use ($alert) {
                return $user->id === $alert->user_id && str_contains((string) $body, '1 bien') && $data['property_type'] === 'land';
            })->andReturn(['success' => true, 'success_count' => 1]);
        $this->artisan('immo:notify-alerts')->assertSuccessful()->expectsOutput('1 alerte(s) envoyée(s).');
        $this->artisan('immo:notify-alerts')->assertSuccessful()->expectsOutput('0 alerte(s) envoyée(s).');
        $this->assertNull($alert->fresh()->pending_until);
    }

    public function test_failed_alert_keeps_its_window_and_retries_without_duplicate_history(): void
    {
        $alert = $this->alert();
        $this->listing();
        DeviceToken::withoutEvents(fn () => DeviceToken::create([
            'user_id' => $alert->user_id, 'token' => 'test-token', 'platform' => 'android', 'is_active' => true,
        ]));
        $this->travel(1)->seconds();
        $this->partialMock(FirebaseMessagingService::class)->shouldReceive('sendToTokens')
            ->once()->andReturn(['success' => true, 'success_count' => 0]);
        $this->artisan('immo:notify-alerts')->assertSuccessful()->expectsOutput('0 alerte(s) envoyée(s).');
        $until = $alert->fresh()->pending_until;
        $since = $alert->fresh()->last_notified_at;
        $this->assertNotNull($until);
        $this->assertDatabaseCount('notifications', 1);
        $this->travel(1)->minutes();
        $this->partialMock(FirebaseMessagingService::class)->shouldReceive('sendToTokens')
            ->once()->andReturn(['success' => true, 'success_count' => 1]);
        $this->artisan('immo:notify-alerts')->assertSuccessful()->expectsOutput('1 alerte(s) envoyée(s).');
        $this->assertTrue($alert->fresh()->last_notified_at->equalTo($until));
        $this->assertTrue($since->lessThan($until));
        $this->assertDatabaseCount('notifications', 1);
        $this->assertSame('real_estate_alert', Notification::sole()->type);
    }

    public function test_alert_exception_is_retried_and_newer_listings_are_not_lost(): void
    {
        $alert = $this->alert();
        $this->listing();
        $this->travel(1)->seconds();
        $this->mock(FirebaseMessagingService::class)->shouldReceive('sendToUser')->once()
            ->andThrow(new \RuntimeException('FCM indisponible'));
        $this->artisan('immo:notify-alerts')->assertSuccessful();
        $pending = $alert->fresh()->pending_until;
        $this->travel(1)->seconds();
        $this->listing();
        $this->travel(1)->seconds();
        $this->mock(FirebaseMessagingService::class)->shouldReceive('sendToUser')->twice()
            ->andReturn(['success' => true]);
        $this->artisan('immo:notify-alerts')->assertSuccessful()->expectsOutput('1 alerte(s) envoyée(s).');
        $this->assertTrue($alert->fresh()->last_notified_at->equalTo($pending));
        $this->artisan('immo:notify-alerts')->assertSuccessful()->expectsOutput('1 alerte(s) envoyée(s).');
        $this->assertTrue($alert->fresh()->last_notified_at->greaterThan($pending));
    }

    public function test_old_mobile_request_preserves_land_type_and_criteria_edits_clear_pending_batch(): void
    {
        $alert = $this->alert();
        $alert->forceFill(['pending_until' => now()])->save();
        Sanctum::actingAs($alert->user);
        $pending = $alert->fresh()->pending_until;
        $this->putJson('/api/v1/real-estate/alert', ['city' => 'Yaoundé'])
            ->assertOk()->assertJsonPath('data.type', 'land');
        $this->assertTrue($alert->fresh()->pending_until->equalTo($pending));
        $this->putJson('/api/v1/real-estate/alert', ['city' => 'Douala'])
            ->assertOk()->assertJsonPath('data.type', 'land');
        $this->assertNull($alert->fresh()->pending_until);
        $this->putJson('/api/v1/real-estate/alert', ['type' => 'rent'])
            ->assertOk()->assertJsonPath('data.type', 'rent');
    }

    public function test_user_without_device_keeps_one_history_entry_and_can_receive_a_later_retry(): void
    {
        $alert = $this->alert();
        $this->listing();
        $this->travel(1)->seconds();
        $this->partialMock(FirebaseMessagingService::class)->shouldReceive('sendToTokens')->never();
        $this->artisan('immo:notify-alerts')->assertSuccessful()->expectsOutput('0 alerte(s) envoyée(s).');
        $this->artisan('immo:notify-alerts')->assertSuccessful()->expectsOutput('0 alerte(s) envoyée(s).');
        $this->assertDatabaseCount('notifications', 1);
        $this->assertNotNull($alert->fresh()->pending_until);
        DeviceToken::withoutEvents(fn () => DeviceToken::create([
            'user_id' => $alert->user_id, 'token' => 'new-device', 'platform' => 'android', 'is_active' => true,
        ]));
        $this->partialMock(FirebaseMessagingService::class)->shouldReceive('sendToTokens')->once()
            ->andReturn(['success' => true, 'success_count' => 1]);
        $this->artisan('immo:notify-alerts')->assertSuccessful()->expectsOutput('1 alerte(s) envoyée(s).');
        $this->assertNull($alert->fresh()->pending_until);
        $this->assertDatabaseCount('notifications', 1);
    }

    public function test_criteria_changed_during_delivery_are_not_overwritten_by_old_batch(): void
    {
        $alert = $this->alert();
        $this->listing();
        $this->travel(1)->seconds();
        $this->mock(FirebaseMessagingService::class)->shouldReceive('sendToUser')->once()
            ->andReturnUsing(function () use ($alert) {
                $alert->forceFill(['city' => 'Douala', 'pending_until' => null, 'last_notified_at' => now()])->save();

                return ['success' => true];
            });
        $this->artisan('immo:notify-alerts')->assertSuccessful();
        $this->assertSame('Douala', $alert->fresh()->city);
        $this->assertNull($alert->fresh()->pending_until);
        $this->assertTrue($alert->fresh()->last_notified_at->equalTo(now()->startOfSecond()));
    }
}
