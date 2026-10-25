<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\User;
use App\Services\FirebaseMessagingService;
use App\Services\NotificationDigestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationDigestTest extends TestCase
{
    use RefreshDatabase;

    public function test_publications_are_grouped_once_and_rendered_in_each_users_language(): void
    {
        $this->freezeTime();
        $fr = User::factory()->create(['locale' => 'fr']);
        $en = User::factory()->create(['locale' => 'en']);
        $service = app(FirebaseMessagingService::class);
        for ($id = 1; $id <= 5; $id++) {
            $service->sendToTopicsLocalized(['fr' => ['Produit', 'Nouveauté'], 'en' => ['Product', 'New item']], ['type' => 'new_product', 'product_id' => (string) $id]);
        }
        $service->sendToTopicsLocalized(['fr' => ['Produit', 'Nouveauté'], 'en' => ['Product', 'New item']], ['type' => 'new_product', 'product_id' => '1']);
        $this->assertDatabaseCount('notification_digest_items', 5);
        $this->assertDatabaseCount('notifications', 0);
        $date = now(config('notifications.digest_timezone'))->toDateString();
        $this->travel(1)->days();
        $digests = app(NotificationDigestService::class);
        $this->assertSame(2, $digests->send($date));
        $this->assertSame(0, $digests->send($date));
        $this->assertDatabaseCount('notifications', 2);
        $this->assertSame('Votre résumé ASSO', Notification::where('user_id', $fr->id)->first()->getRawOriginal('title'));
        $this->assertSame('Your ASSO summary', Notification::where('user_id', $en->id)->first()->getRawOriginal('title'));
        $this->actingAs($en, 'sanctum')->withHeader('Accept-Language', 'en')->getJson('/api/v1/notifications/digests/'.$date)->assertOk()->assertJsonCount(5, 'items')->assertJsonPath('items.0.title', 'Product');
    }

    public function test_opted_out_user_has_no_digest_and_cannot_open_someone_elses_digest(): void
    {
        $this->freezeTime();
        $user = User::factory()->create();
        $off = User::factory()->create();
        $off->forceFill(['notification_preferences' => ['categories' => ['marketing' => false]]])->save();
        $date = now(config('notifications.digest_timezone'))->toDateString();
        app(FirebaseMessagingService::class)->sendToAllLocalized(['fr' => ['Info', 'Annonce'], 'en' => ['News', 'Announcement']], ['type' => 'announcement']);
        $this->travel(1)->days();
        app(NotificationDigestService::class)->send($date);
        $this->assertSame(0, Notification::where('user_id', $off->id)->count());
        $this->actingAs($off, 'sanctum')->getJson('/api/v1/notifications/digests/'.$date)->assertNotFound();
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/notifications/digests/'.$date)->assertOk();
    }

    public function test_targeted_promotions_stay_private_and_do_not_reach_other_users(): void
    {
        $this->freezeTime();
        $one = User::factory()->create();
        $two = User::factory()->create();
        $date = now(config('notifications.digest_timezone'))->toDateString();
        $fcm = app(FirebaseMessagingService::class);
        $fcm->sendToUser($one, 'Offre privée', 'Destinée à un compte', ['type' => 'announcement']);
        $fcm->sendToAll('Annonce générale', 'Pour tous', ['type' => 'announcement']);
        $this->travel(1)->days();
        app(NotificationDigestService::class)->send($date);
        $this->actingAs($two, 'sanctum')->getJson('/api/v1/notifications/digests/'.$date)->assertOk()->assertJsonCount(1, 'items')->assertDontSee('Offre privée');
        $this->actingAs($one, 'sanctum')->getJson('/api/v1/notifications/digests/'.$date)->assertOk()->assertJsonCount(2, 'items');
    }

    public function test_digest_respects_douala_midnight_and_rejects_invalid_dates(): void
    {
        $this->travelTo(\Carbon\Carbon::parse('2026-10-25 23:15:00', 'UTC'));
        $user = User::factory()->create();
        app(FirebaseMessagingService::class)->sendToAll('Info', 'Nouvelle journée', ['type' => 'announcement']);
        $digests = app(NotificationDigestService::class);
        $this->assertSame(0, $digests->send('2026-10-25'));
        $this->assertSame(1, $digests->send('2026-10-26'));
        $this->artisan('notifications:send-digests', ['--date' => '2026-02-31'])->assertFailed();
    }
}
