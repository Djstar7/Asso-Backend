<?php

namespace App\Services;

use App\Models\DeviceToken;
use App\Models\Notification;
use App\Models\User;
use App\Support\Translation\LocalizedText;

/** Compatibility facade: all legacy callers share preferences, history and durable delivery. */
class FcmService
{
    public function sendToUser(User $user, string|LocalizedText $title, string|LocalizedText $body, array $data = []): bool
    {
        return app(FirebaseMessagingService::class)->sendToUser($user, $title, $body, $data)['success'] ?? false;
    }

    /** Resolve the registered owner; unknown or inactive tokens must not bypass account preferences. */
    public function sendToToken(string $token, string $title, string $body, array $data = []): bool
    {
        $user = DeviceToken::active()->where('token', $token)->first()?->user;

        return $user ? $this->sendToUser($user, $title, $body, $data) : false;
    }

    public function sendAndStore(User $user, string $type, string|LocalizedText $title, string|LocalizedText $body, array $data = []): ?Notification
    {
        $result = app(FirebaseMessagingService::class)->sendToUser($user, $title, $body, array_replace($data, ['type' => $type]));

        return isset($result['notification_id']) ? Notification::find($result['notification_id']) : null;
    }

    /**
     * Send package purchase notification
     */
    public function sendPackagePurchaseNotification(User $user, array $packageData): ?Notification
    {
        $packageName = $packageData['name'] ?? 'Package';
        $storageTotal = $packageData['storage_total'] ?? '0';
        $expiresAt = $packageData['expires_at'] ?? '';

        $title = $user->localized('notifications.package_purchase.title');
        $body = $user->localized('notifications.package_purchase.body', [
            'package' => $packageName,
            'storage' => $storageTotal,
        ]);

        return $this->sendAndStore(
            $user,
            'package_purchase',
            $title,
            $body,
            [
                'type' => 'package_purchase',
                'package_name' => $packageName,
                'storage_total' => $storageTotal,
                'expires_at' => $expiresAt,
                'screen' => 'vendor_dashboard',
            ]
        );
    }

    /**
     * Send package expiration warning notification
     */
    public function sendPackageExpiringNotification(User $user, int $daysRemaining): ?Notification
    {
        $title = $user->localized('notifications.package_expiring.title');
        $body = $user->localized(
            $daysRemaining > 1 ? 'notifications.package_expiring.body_plural' : 'notifications.package_expiring.body_singular',
            ['days' => $daysRemaining]
        );

        return $this->sendAndStore(
            $user,
            'package_expiring',
            $title,
            $body,
            [
                'type' => 'package_expiring',
                'days_remaining' => $daysRemaining,
                'screen' => 'package_subscription',
            ]
        );
    }

    /**
     * Send order notification
     */
    public function sendOrderNotification(User $user, string $orderStatus, array $orderData): ?Notification
    {
        $orderNumber = $orderData['order_number'] ?? 'N/A';

        $event = in_array($orderStatus, ['pending', 'confirmed', 'shipped', 'delivered', 'cancelled'], true)
            ? 'order_status_'.$orderStatus
            : 'order_status_update';

        $title = $user->localized("notifications.{$event}.title");
        $body = $user->localized("notifications.{$event}.body", ['order_number' => $orderNumber]);

        return $this->sendAndStore(
            $user,
            'order_'.$orderStatus,
            $title,
            $body,
            [
                'type' => 'order',
                'order_status' => $orderStatus,
                'order_number' => $orderNumber,
                'screen' => 'order_details',
                'order_id' => $orderData['order_id'] ?? null,
            ]
        );
    }
}
