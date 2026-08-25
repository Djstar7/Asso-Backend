<?php

namespace App\Services;

use App\Models\User;
use App\Models\Notification;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Factory;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification as FirebaseNotification;

class FcmService
{
    protected $messaging;

    public function __construct()
    {
        try {
            $serviceAccountPath = storage_path('app/private/firebase/service-account.json');

            if (file_exists($serviceAccountPath)) {
                $factory = (new Factory)->withServiceAccount($serviceAccountPath);
                $this->messaging = $factory->createMessaging();
                Log::info('[FCM] Firebase messaging initialized successfully');
            } else {
                Log::warning('[FCM] Service account file not found - FCM notifications disabled');
                $this->messaging = null;
            }
        } catch (\Exception $e) {
            Log::error('[FCM] Error initializing Firebase messaging', [
                'error' => $e->getMessage(),
            ]);
            $this->messaging = null;
        }
    }

    /**
     * Send push notification to a user
     */
    public function sendToUser(User $user, string $title, string $body, array $data = []): bool
    {
        if (!$user->fcm_token) {
            Log::warning('[FCM] User has no FCM token', ['user_id' => $user->id]);
            return false;
        }

        return $this->sendToToken($user->fcm_token, $title, $body, $data);
    }

    /**
     * Send push notification to a specific FCM token
     */
    public function sendToToken(string $token, string $title, string $body, array $data = []): bool
    {
        if ($this->messaging === null) {
            Log::warning('[FCM] Messaging not initialized - FCM notifications disabled');
            return false;
        }

        try {
            $notification = FirebaseNotification::create($title, $body);

            $message = CloudMessage::withTarget('token', $token)
                ->withNotification($notification)
                ->withData(array_merge($data, [
                    'click_action' => 'FLUTTER_NOTIFICATION_CLICK',
                ]));

            $this->messaging->send($message);

            Log::info('[FCM] Notification sent successfully', [
                'title' => $title,
                'token' => substr($token, 0, 20) . '...',
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('[FCM] Failed to send notification', [
                'error' => $e->getMessage(),
                'title' => $title,
            ]);
            return false;
        }
    }

    /**
     * Send push notification and create notification record
     */
    public function sendAndStore(User $user, string $type, string $title, string $body, array $data = []): ?Notification
    {
        // Create notification record
        $notification = Notification::create([
            'user_id' => $user->id,
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'data' => $data,
            'is_read' => false,
            'is_sent' => false,
        ]);

        // Send FCM push notification only if messaging is initialized
        if ($this->messaging !== null) {
            $sent = $this->sendToUser($user, $title, $body, array_merge($data, [
                'notification_id' => $notification->id,
            ]));

            // Update notification status
            if ($sent) {
                $notification->update([
                    'is_sent' => true,
                    'sent_at' => now(),
                ]);
            }
        } else {
            Log::info('[FCM] Notification created but not sent (FCM not configured)', [
                'notification_id' => $notification->id,
                'type' => $type,
            ]);
        }

        return $notification;
    }

    /**
     * Send package purchase notification
     */
    public function sendPackagePurchaseNotification(User $user, array $packageData): ?Notification
    {
        $packageName = $packageData['name'] ?? 'Package';
        $storageTotal = $packageData['storage_total'] ?? '0';
        $expiresAt = $packageData['expires_at'] ?? '';

        $title = 'Achat confirmé !';
        $body = "Votre package {$packageName} ({$storageTotal} MB) a été activé avec succès.";

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
        $title = 'Package bientôt expiré';
        $body = "Votre package expire dans {$daysRemaining} jour" . ($daysRemaining > 1 ? 's' : '') . '. Renouvelez-le pour continuer à publier vos produits.';

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

        $statusMessages = [
            'pending' => ['Nouvelle commande', "Commande #{$orderNumber} reçue !"],
            'confirmed' => ['Commande confirmée', "Commande #{$orderNumber} confirmée"],
            'shipped' => ['Commande expédiée', "Commande #{$orderNumber} en cours de livraison"],
            'delivered' => ['Commande livrée', "Commande #{$orderNumber} livrée avec succès"],
            'cancelled' => ['Commande annulée', "Commande #{$orderNumber} annulée"],
        ];

        [$title, $body] = $statusMessages[$orderStatus] ?? ['Mise à jour commande', "Commande #{$orderNumber}"];

        return $this->sendAndStore(
            $user,
            'order_' . $orderStatus,
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
