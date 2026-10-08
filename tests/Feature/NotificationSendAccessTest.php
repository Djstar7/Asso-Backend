<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\User;
use App\Services\FirebaseMessagingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Seul le compte admin peut envoyer une notification à d'autres utilisateurs par l'API. */
class NotificationSendAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_regular_user_cannot_send_to_others(): void
    {
        $user = User::factory()->create(['role' => 'client']);
        $target = User::factory()->create();

        $routes = [
            'send-to-user' => ['user_id' => $target->id, 'title' => 'Faux', 'body' => 'Message'],
            'send-to-users' => ['user_ids' => [$target->id], 'title' => 'Faux', 'body' => 'Message'],
            'send-to-all' => ['title' => 'Faux', 'body' => 'Message'],
            'send-to-topic' => ['topic' => 'all_users', 'title' => 'Faux', 'body' => 'Message'],
        ];

        foreach ($routes as $route => $payload) {
            $this->actingAs($user, 'sanctum')
                ->postJson("/api/v1/notifications/{$route}", $payload)
                ->assertForbidden();
        }

        $this->assertSame(0, Notification::count());
    }

    public function test_guest_is_rejected(): void
    {
        $this->postJson('/api/v1/notifications/send-to-all', ['title' => 'Faux', 'body' => 'Message'])
            ->assertUnauthorized();
    }

    public function test_admin_can_still_send(): void
    {
        $this->mock(FirebaseMessagingService::class, function ($mock) {
            $mock->shouldReceive('sendToUser')->once()->andReturn(['success' => true]);
        });

        $admin = User::factory()->create(['role' => 'admin']);
        $target = User::factory()->create();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/notifications/send-to-user', [
                'user_id' => $target->id, 'title' => 'Info', 'body' => 'Message', 'data' => ['type' => 'announcement'],
            ])
            ->assertOk();
    }
}
