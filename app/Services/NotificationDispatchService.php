<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\NotificationPushDelivery;
use App\Models\User;
use App\Support\Translation\LocalizedText;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/** Durable per-device delivery: Firebase acceptance is distinct from inbox storage and reading. */
class NotificationDispatchService
{
    public function send(User $user, string|LocalizedText $title, string|LocalizedText $body, array $data = [], ?string $platform = null): array
    {
        $deferred = DB::transactionLevel() > 0;
        $result = DB::transaction(function () use ($user, $title, $body, $data, $platform) {
            $user = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $category = NotificationPreferences::category($data['type'] ?? 'system');
            $canonical = $this->canonical([$title instanceof LocalizedText ? $title->toArray() : $title,
                $body instanceof LocalizedText ? $body->toArray() : $body,
                array_diff_key($data, array_flip(['timestamp', 'notification_id', 'badge_count']))]);
            $fingerprint = hash('sha256', json_encode($canonical, JSON_UNESCAPED_UNICODE));
            $explicit = isset($data['notification_key']) ? hash('sha256', (string) $data['notification_key']) : null;
            $query = Notification::where('user_id', $user->id);
            $existing = $explicit ? $query->where('event_key', $explicit)->first()
                : $query->where('fingerprint', $fingerprint)->where('created_at', '>=', now()->subMinutes(config('notifications.deduplication_minutes')))->latest('id')->first();
            if ($existing) {
                if ($existing->push_status === 'no_device' && NotificationPreferences::allows($user, $category)) {
                    foreach ($user->deviceTokens()->active()->when($platform, fn ($q) => $q->where('platform', $platform))->get() as $token) {
                        NotificationPushDelivery::firstOrCreate(['notification_id' => $existing->id, 'device_token_id' => $token->id], ['next_attempt_at' => now()]);
                    }
                    if (NotificationPushDelivery::where('notification_id', $existing->id)->exists()) {
                        $existing->update(['push_status' => 'queued']);
                    }
                }

                return ['notification' => $existing, 'duplicate' => true];
            }
            $allowed = NotificationPreferences::allows($user, $category);
            $tokens = $user->deviceTokens()->active()->when($platform, fn ($q) => $q->where('platform', $platform))->get();
            $notification = Notification::create(['user_id' => $user->id, 'category' => $category,
                'event_key' => $explicit, 'fingerprint' => $fingerprint,
                'title' => $title instanceof LocalizedText ? $title->render($user->preferredLocale()) : $title,
                'body' => $body instanceof LocalizedText ? $body->render($user->preferredLocale()) : $body,
                'type' => $data['type'] ?? 'system', 'data' => Notification::withI18n($data, $title, $body),
                'is_read' => false, 'is_sent' => false, 'sent_at' => null,
                'push_status' => ! $allowed ? 'suppressed' : ($tokens->isEmpty() ? 'no_device' : 'queued')]);
            if ($allowed) {
                foreach ($tokens as $token) {
                    NotificationPushDelivery::create([
                        'notification_id' => $notification->id, 'device_token_id' => $token->id, 'next_attempt_at' => now()]);
                }
            }

            return ['notification' => $notification, 'duplicate' => false];
        });
        $notification = $result['notification'];
        if (! $result['duplicate']) {
            if ($deferred) {
                DB::afterCommit(fn () => $this->deliverNotification($notification->id));
            } else {
                $this->deliverNotification($notification->id);
            }
        }
        $notification->refresh();

        return ['success' => in_array($notification->push_status, ['queued', 'accepted', 'suppressed'], true),
            'notification_id' => $notification->id, 'delivery_status' => $notification->push_status,
            'queued' => $notification->push_status === 'queued', 'duplicate' => $result['duplicate'],
            'message' => __('notifications.delivery.'.$notification->push_status)];
    }

    private function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn ($item) => $this->canonical($item), $value);
    }

    private function deliverNotification(int $id): void
    {
        NotificationPushDelivery::where('notification_id', $id)->where('status', 'pending')->eachById(fn ($delivery) => $this->deliver($delivery->id));
    }

    public function retry(): void
    {
        NotificationPushDelivery::whereIn('status', ['pending', 'sending'])->where('next_attempt_at', '<=', now())
            ->eachById(function ($delivery) {
                try {
                    $this->deliver($delivery->id);
                } catch (\Throwable $e) {
                    Log::warning('Notification delivery retry failed', ['delivery_id' => $delivery->id, 'exception' => get_class($e)]);
                }
            });
    }

    public function deliver(int $id): void
    {
        $claimed = DB::transaction(function () use ($id) {
            $delivery = NotificationPushDelivery::with(['notification.user', 'device'])->whereKey($id)->lockForUpdate()->first();
            if (! $delivery || ! in_array($delivery->status, ['pending', 'sending'], true) || $delivery->next_attempt_at?->isFuture()) {
                return null;
            }
            $notification = $delivery->notification;
            $user = $notification?->user;
            $device = $delivery->device;
            if (! $user || ! $device || $device->user_id !== $user->id || ! $device->is_active || ! NotificationPreferences::allows($user, $notification->category)) {
                $delivery->update(['status' => 'suppressed', 'next_attempt_at' => null]);

                return ['skip' => true, 'notification_id' => $notification?->id];
            }
            if ($delivery->attempts >= config('notifications.max_attempts')) {
                $delivery->update(['status' => 'failed', 'next_attempt_at' => null]);

                return ['skip' => true, 'notification_id' => $notification->id];
            }
            $delivery->update(['status' => 'sending', 'attempts' => $delivery->attempts + 1,
                'next_attempt_at' => now()->addSeconds(config('notifications.delivery_lease_seconds'))]);

            return ['delivery' => $delivery, 'notification' => $notification, 'device' => $device, 'user' => $user];
        });
        if (! $claimed) {
            return;
        }
        if ($claimed['skip'] ?? false) {
            if ($claimed['notification_id']) {
                $this->updateStatus($claimed['notification_id']);
            }

            return;
        }
        ['delivery' => $delivery, 'notification' => $notification, 'device' => $device, 'user' => $user] = $claimed;
        $texts = array_map(fn ($field) => LocalizedText::fromArray($notification->data['i18n'][$field] ?? null)?->render($user->preferredLocale())
            ?? $notification->getRawOriginal($field), ['title', 'body']);
        $data = array_diff_key($notification->data ?? [], ['i18n' => true]);
        $data = array_replace($data, ['notification_id' => (string) $notification->id, 'category' => $notification->category,
            'badge_count' => (string) $user->notifications()->unread()->count()]);
        try {
            $result = app(FirebaseMessagingService::class)->sendToTokens([$device->token], $texts[0], $texts[1], $data);
        } catch (\Throwable $e) {
            $result = ['success' => false, 'retryable' => true, 'error_code' => 'UNAVAILABLE'];
        }
        DB::transaction(function () use ($delivery, $result) {
            $current = NotificationPushDelivery::whereKey($delivery->id)->lockForUpdate()->first();
            if (! $current || $current->status !== 'sending' || $current->attempts !== $delivery->attempts) {
                return;
            }
            if (($result['success_count'] ?? 0) > 0) {
                $current->update(['status' => 'accepted', 'accepted_at' => now(), 'next_attempt_at' => null, 'error_code' => null]);
            } else {
                $retry = ($result['retryable'] ?? false) && $current->attempts < config('notifications.max_attempts');
                $delays = config('notifications.retry_delays_seconds');
                $delay = $delays[min($current->attempts - 1, count($delays) - 1)];
                $current->update(['status' => $retry ? 'pending' : 'failed', 'error_code' => $result['error_code'] ?? 'UNKNOWN',
                    'next_attempt_at' => $retry ? now()->addSeconds($delay) : null]);
            }
        });
        $this->updateStatus($notification->id);
    }

    private function updateStatus(int $id): void
    {
        DB::transaction(function () use ($id) {
            $notification = Notification::whereKey($id)->lockForUpdate()->first();
            if (! $notification) {
                return;
            }
            $deliveries = NotificationPushDelivery::where('notification_id', $id)->get();
            $accepted = $deliveries->where('status', 'accepted')->isNotEmpty();
            $pending = $deliveries->whereIn('status', ['pending', 'sending'])->isNotEmpty();
            $status = $accepted ? 'accepted' : ($pending ? 'queued' : ($deliveries->where('status', 'failed')->isNotEmpty() ? 'failed' : 'suppressed'));
            $notification->update(['push_status' => $status, 'is_sent' => $accepted,
                'sent_at' => $accepted ? ($notification->sent_at ?? now()) : null]);
        });
    }
}
