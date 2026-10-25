<?php

namespace App\Services;

use App\Models\NotificationDigestItem;
use App\Models\User;
use Carbon\CarbonImmutable;

class NotificationDigestService
{
    public function enqueue(array $texts, array $data = []): array
    {
        // An entity publication is one event, regardless of language/topic retries.
        $type = $data['type'] ?? 'announcement';
        $entity = $data['product_boost_id'] ?? $data['product_id'] ?? $data['offer_id'] ?? null;
        $key = $data['notification_key'] ?? ($entity !== null ? $type.':'.$entity
            : 'announcement:'.now(config('notifications.digest_timezone'))->toDateString().':'.hash('sha256', json_encode([$texts, $data])));
        $item = NotificationDigestItem::firstOrCreate(['event_key' => hash('sha256', (string) $key.':'.json_encode($data['recipient_ids'] ?? []))], ['texts' => $texts, 'data' => $data]);

        return ['success' => true, 'queued' => true, 'delivery_status' => 'digest_queued',
            'duplicate' => ! $item->wasRecentlyCreated, 'message' => __('notifications.digest.queued')];
    }

    public function range(string $date): array
    {
        $start = CarbonImmutable::createFromFormat('!Y-m-d', $date, config('notifications.digest_timezone'));
        if (! $start || $start->format('Y-m-d') !== $date) {
            throw new \InvalidArgumentException('Invalid digest date');
        }

        return [$start->utc(), $start->addDay()->utc()];
    }

    public function send(string $date): int
    {
        [$start, $end] = $this->range($date);
        $items = NotificationDigestItem::where('created_at', '>=', $start)->where('created_at', '<', $end)->get();
        if ($items->isEmpty()) {
            return 0;
        }
        $count = 0;
        User::where('created_at', '<', $end)->eachById(function (User $user) use ($date, $items, &$count) {
            if ($user->isTechnicalAccount() || ! NotificationPreferences::allows($user, 'marketing')) {
                return;
            }
            $eligible = $items->filter(fn ($item) => $item->created_at->greaterThanOrEqualTo($user->created_at) && (! isset($item->data['recipient_ids']) || in_array($user->id, $item->data['recipient_ids'], true)));
            if ($eligible->isEmpty()) {
                return;
            }
            $result = app(FirebaseMessagingService::class)->sendToUser($user,
                $user->localized('notifications.digest.title'),
                $user->localized($eligible->count() === 1 ? 'notifications.digest.body_one' : 'notifications.digest.body', ['count' => $eligible->count()]),
                ['type' => 'marketing_digest', 'digest_date' => $date, 'notification_key' => 'marketing-digest:'.$date,
                    'action' => 'open_notification_digest']);
            if (! ($result['duplicate'] ?? false)) {
                $count++;
            }
        });

        return $count;
    }
}
