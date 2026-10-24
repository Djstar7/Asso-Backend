<?php

namespace Tests\Feature;

use App\Models\DeviceToken;
use App\Models\Notification;
use App\Models\NotificationPushDelivery;
use App\Models\User;
use App\Services\FcmFailurePolicy;
use App\Services\FirebaseMessagingService;
use App\Services\NotificationDispatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Kreait\Firebase\Exception\Messaging\NotFound;
use Kreait\Firebase\Exception\Messaging\ServerUnavailable;
use Kreait\Firebase\Messaging\MessageTarget;
use Kreait\Firebase\Messaging\MulticastSendReport;
use Kreait\Firebase\Messaging\SendReport;
use Tests\TestCase;

class NotificationDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private function device(User $user, string $token): DeviceToken
    {
        return DeviceToken::withoutEvents(fn () => DeviceToken::create(['user_id' => $user->id,
            'token' => $token, 'platform' => 'ios', 'is_active' => true]));
    }

    private function transport(callable $callback): FirebaseMessagingService
    {
        $service = new FirebaseMessagingService;
        $gateway = \Mockery::mock();
        $gateway->shouldReceive('sendMulticast')->andReturnUsing($callback);
        (new \ReflectionProperty($service, 'messaging'))->setValue($service, $gateway);
        $this->app->instance(FirebaseMessagingService::class, $service);

        return $service;
    }

    public function test_transient_failure_keeps_token_and_retries_only_failed_device(): void
    {
        $user = User::factory()->create();
        $this->device($user, 'device-one');
        $second = $this->device($user, 'device-two');
        $calls = [];
        $secondFails = true;
        $service = $this->transport(function ($message, $tokens) use (&$calls, &$secondFails) {
            $calls[] = $tokens[0];
            $target = MessageTarget::with('token', $tokens[0]);

            return MulticastSendReport::withItems([$tokens[0] === 'device-two' && $secondFails
                ? SendReport::failure($target, new ServerUnavailable('Temporary outage')) : SendReport::success($target, ['name' => 'accepted'])]);
        });
        $result = $service->sendToUser($user, 'Commande', 'Votre commande est prête.', ['type' => 'order_update', 'notification_key' => 'order:7:ready']);
        $this->assertTrue($second->fresh()->is_active);
        $this->assertDatabaseCount('notifications', 1);
        $this->assertSame('accepted', Notification::sole()->push_status);
        app(NotificationDispatchService::class)->retry();
        $this->assertCount(2, $calls);
        $this->travel(61)->seconds();
        $secondFails = false;
        app(NotificationDispatchService::class)->retry();
        app(NotificationDispatchService::class)->retry();
        $this->assertSame(['device-one', 'device-two', 'device-two'], $calls);
        $this->assertSame(2, NotificationPushDelivery::where('status', 'accepted')->count());
        $this->assertDatabaseCount('notifications', 1);
    }

    public function test_invalid_token_is_disabled_and_zero_success_is_not_reported_as_success(): void
    {
        $user = User::factory()->create();
        $device = $this->device($user, 'revoked-token');
        $service = $this->transport(fn ($message, $tokens) => MulticastSendReport::withItems([
            SendReport::failure(MessageTarget::with('token', $tokens[0]), NotFound::becauseTokenNotFound($tokens[0]))]));
        $result = $service->sendToUser($user, 'Test', 'Message', ['type' => 'test']);
        $this->assertFalse($result['success']);
        $this->assertFalse($device->fresh()->is_active);
        $this->assertSame('failed', Notification::sole()->push_status);
        $this->assertFalse(Notification::sole()->is_sent);
        $this->assertNull(Notification::sole()->sent_at);
    }

    public function test_payload_error_does_not_invalidate_a_device(): void
    {
        $error = (new ServerUnavailable('Bad payload'))->withErrors(['error' => ['details' => [['errorCode' => 'INVALID_ARGUMENT']]]]);
        $policy = FcmFailurePolicy::classify($error);
        $this->assertFalse($policy['invalid_token']);
        $this->assertFalse($policy['retryable']);
        $this->assertSame('INVALID_ARGUMENT', $policy['error_code']);
    }

    public function test_preferences_suppress_push_but_preserve_inbox_and_are_user_scoped(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $this->device($user, 'device');
        $this->actingAs($user, 'sanctum')->putJson('/api/v1/notifications/preferences', ['categories' => ['orders' => false]])
            ->assertOk()->assertJsonPath('preferences.categories.orders', false)->assertJsonPath('preferences.categories.payments', true);
        $this->actingAs($other, 'sanctum')->getJson('/api/v1/notifications/preferences')->assertOk()->assertJsonPath('preferences.categories.orders', true);
        $service = $this->transport(function () {
            $this->fail('Suppressed push must not be sent');
        });
        $result = $service->sendToUser($user->fresh(), 'Commande', 'Votre commande', ['type' => 'order_update']);
        $this->assertSame('suppressed', $result['delivery_status']);
        $this->assertDatabaseCount('notifications', 1);
        $this->assertDatabaseCount('notification_push_deliveries', 0);
        $this->actingAs($user, 'sanctum')->putJson('/api/v1/notifications/preferences', ['categories' => ['invented_category' => true]])->assertUnprocessable();
    }

    public function test_retry_observes_new_preferences_and_changed_device_owner(): void
    {
        $user = User::factory()->create();
        $device = $this->device($user, 'shared-device');
        $calls = 0;
        $service = $this->transport(function ($message, $tokens) use (&$calls) {
            $calls++;

            return MulticastSendReport::withItems([SendReport::failure(MessageTarget::with('token', $tokens[0]), new ServerUnavailable('Try later'))]);
        });
        $service->sendToUser($user, 'Commande', 'Informations', ['type' => 'order_update']);
        $device->update(['user_id' => User::factory()->create()->id]);
        $this->travel(61)->seconds();
        app(NotificationDispatchService::class)->retry();
        $this->assertSame(1, $calls);
        $this->assertSame('suppressed', NotificationPushDelivery::sole()->status);
    }

    public function test_identical_event_is_not_sent_again_even_across_a_deduplication_time_boundary(): void
    {
        $user = User::factory()->create();
        $this->device($user, 'device');
        $calls = 0;
        $service = $this->transport(function ($message, $tokens) use (&$calls) {
            $calls++;

            return MulticastSendReport::withItems([SendReport::success(MessageTarget::with('token', $tokens[0]), ['name' => 'ok'])]);
        });
        $service->sendToUser($user, 'Commande', 'Prête', ['type' => 'order_update', 'order_id' => '8']);
        $this->travel(2)->minutes();
        $second = $service->sendToUser($user, 'Commande', 'Prête', ['order_id' => '8', 'type' => 'order_update']);
        $this->assertTrue($second['duplicate']);
        $this->assertSame(1, $calls);
        $this->assertDatabaseCount('notifications', 1);
    }

    public function test_delivery_is_deferred_until_commit_and_rollback_never_sends(): void
    {
        $user = User::factory()->create();
        $this->device($user, 'device');
        $service = $this->transport(function () {
            $this->fail('Rollback must not send a push');
        });
        \Illuminate\Support\Facades\DB::beginTransaction();
        $service->sendToUser($user, 'Test', 'Message', ['type' => 'test']);
        \Illuminate\Support\Facades\DB::rollBack();
        $this->assertDatabaseCount('notifications', 0);
        $this->assertDatabaseCount('notification_push_deliveries', 0);
    }

    public function test_quiet_marketing_payload_uses_brand_icon_and_stable_collapsing_without_constant_badge(): void
    {
        [$android, $apns] = (new FirebaseMessagingService)->deliveryConfiguration(['type' => 'marketing_digest', 'notification_id' => '9']);
        $this->assertSame('asso_updates_v1', $android['notification']['channel_id']);
        $this->assertSame('ic_stat_asso', $android['notification']['icon']);
        $this->assertArrayNotHasKey('sound', $apns['payload']['aps']);
        $this->assertArrayNotHasKey('badge', $apns['payload']['aps']);
        $this->assertSame(64, strlen($apns['headers']['apns-collapse-id']));
    }
}
