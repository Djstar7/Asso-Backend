<?php

namespace App\Services;

use App\Http\Middleware\SetLocale;
use App\Models\DeviceToken;
use App\Models\User;
use App\Models\Notification as NotificationModel;
use Kreait\Firebase\Factory;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification;
use Kreait\Firebase\Messaging\AndroidConfig;
use Kreait\Firebase\Messaging\ApnsConfig;
use Illuminate\Support\Facades\Log;
use Exception;

class FirebaseMessagingService
{
    protected $messaging;

    /** Topic des annonces des anciennes versions de l'app (français). */
    public const TOPIC_ALL = 'all_users';

    /** Topic des annonces dans une langue (all_users_fr, all_users_en). */
    public static function localeTopic(string $locale): string
    {
        return self::TOPIC_ALL . '_' . $locale;
    }

    /** @return array<int, string> */
    public static function announcementTopics(): array
    {
        return array_merge([self::TOPIC_ALL], array_map(fn ($l) => self::localeTopic($l), SetLocale::SUPPORTED));
    }

    /**
     * Annonce à tous dans la langue de chacun : un envoi par topic de langue,
     * plus le français sur all_users pour les versions de l'app qui ne
     * connaissent pas encore les topics par langue.
     *
     * @param  array<string, array{0: string, 1: string}>  $texts  langue => [titre, texte]
     */
    public function sendToTopicsLocalized(array $texts, array $data = []): array
    {
        $fr = $texts['fr'];
        $results = [self::TOPIC_ALL => $this->sendToTopic(self::TOPIC_ALL, $fr[0], $fr[1], $data)];
        foreach (SetLocale::SUPPORTED as $locale) {
            [$title, $body] = $texts[$locale] ?? $fr;
            $results[self::localeTopic($locale)] = $this->sendToTopic(self::localeTopic($locale), $title, $body, $data);
        }

        $success = collect($results)->contains(fn ($r) => $r['success'] ?? false);

        return [
            'success' => $success,
            'message' => $success ? 'Notification sent to topics' : ($results[self::TOPIC_ALL]['message'] ?? 'Topic send failed'),
            'results' => $results,
        ];
    }

    /**
     * Comme sendToAll, mais chaque utilisateur garde dans son historique le
     * texte de sa langue.
     *
     * @param  array<string, array{0: string, 1: string}>  $texts  langue => [titre, texte]
     */
    public function sendToAllLocalized(array $texts, array $data = []): array
    {
        User::query()->select(['id', 'locale'])->chunkById(500, function ($users) use ($texts, $data) {
            foreach ($users as $user) {
                [$title, $body] = $texts[$user->locale] ?? $texts['fr'];
                $this->saveNotificationToDatabase($user->id, $title, $body, $data);
            }
        });

        return $this->sendToTopicsLocalized($texts, $data);
    }

    public function __construct()
    {
        try {
            // Chemin vers le fichier de configuration Firebase
            $serviceAccountPath = storage_path('app/private/firebase/service-account.json');

            if (!file_exists($serviceAccountPath)) {
                Log::warning('Firebase service account file not found at: ' . $serviceAccountPath);
                return;
            }

            $factory = (new Factory)
                ->withServiceAccount($serviceAccountPath);

            $this->messaging = $factory->createMessaging();
        } catch (Exception $e) {
            Log::error('Firebase Messaging initialization error: ' . $e->getMessage());
        }
    }

    /**
     * Envoie une notification à un utilisateur spécifique
     *
     * @param User $user
     * @param string $title
     * @param string $body
     * @param array $data
     * @param string|null $platform
     * @return array
     */
    public function sendToUser(User $user, string $title, string $body, array $data = [], ?string $platform = null): array
    {
        // Enregistrée d'abord : l'app reçoit l'id et peut marquer la notification lue.
        $notificationId = $this->saveNotificationToDatabase($user->id, $title, $body, $data);
        $data = self::stringifyData($data + ($notificationId ? ['notification_id' => $notificationId] : []));

        $tokens = $user->deviceTokens()->active();

        if ($platform) {
            $tokens = $tokens->platform($platform);
        }

        $tokens = $tokens->pluck('token')->toArray();

        if (empty($tokens)) {
            Log::info("No active tokens found for user {$user->id}");
            return [
                'success' => false,
                'message' => 'No active device tokens found for this user',
            ];
        }

        return $this->sendToTokens($tokens, $title, $body, $data);
    }

    /** FCM n'accepte que des chaînes dans `data` : null devient '' et les tableaux du JSON. */
    public static function stringifyData(array $data): array
    {
        return array_map(
            fn ($value) => is_array($value) || is_object($value)
                ? json_encode($value, JSON_UNESCAPED_UNICODE)
                : (string) ($value ?? ''),
            $data,
        );
    }

    /**
     * Envoie une notification à plusieurs utilisateurs
     *
     * @param array $userIds
     * @param string $title
     * @param string $body
     * @param array $data
     * @return array
     */
    public function sendToUsers(array $userIds, string $title, string $body, array $data = []): array
    {
        $tokens = DeviceToken::whereIn('user_id', $userIds)
            ->active()
            ->pluck('token')
            ->toArray();

        if (empty($tokens)) {
            // Enregistrer quand même les notifications dans la BDD
            foreach ($userIds as $userId) {
                $this->saveNotificationToDatabase($userId, $title, $body, $data);
            }
            return [
                'success' => false,
                'message' => 'No active device tokens found for these users',
            ];
        }

        $result = $this->sendToTokens($tokens, $title, $body, $data);

        // Enregistrer les notifications dans la BDD pour chaque utilisateur
        foreach ($userIds as $userId) {
            $this->saveNotificationToDatabase($userId, $title, $body, $data);
        }

        return $result;
    }

    /**
     * Envoie une notification à tous les utilisateurs
     * Utilise le système de topics pour les envois en masse
     *
     * @param string $title
     * @param string $body
     * @param array $data
     * @return array
     */
    public function sendToAll(string $title, string $body, array $data = []): array
    {
        // Enregistrer la notification pour tous les utilisateurs
        $allUserIds = User::pluck('id')->toArray();
        foreach ($allUserIds as $userId) {
            $this->saveNotificationToDatabase($userId, $title, $body, $data);
        }

        // Utiliser le topic pour les envois en masse (plus efficace)
        return $this->sendToTopic('all_users', $title, $body, $data);
    }

    /**
     * Envoie une notification à tous les utilisateurs par batch
     * Méthode de secours si le topic n'est pas configuré
     *
     * @param string $title
     * @param string $body
     * @param array $data
     * @param int $batchSize
     * @return array
     */
    public function sendToAllByBatch(string $title, string $body, array $data = [], int $batchSize = 500): array
    {
        $allTokens = DeviceToken::active()->pluck('token')->toArray();

        if (empty($allTokens)) {
            return [
                'success' => false,
                'message' => 'No active device tokens found',
            ];
        }

        $totalSuccess = 0;
        $totalFailure = 0;
        $batches = array_chunk($allTokens, $batchSize);

        foreach ($batches as $index => $batch) {
            Log::info("Sending notification batch " . ($index + 1) . "/" . count($batches));

            $result = $this->sendToTokens($batch, $title, $body, $data);

            if ($result['success']) {
                $totalSuccess += $result['success_count'] ?? 0;
                $totalFailure += $result['failure_count'] ?? 0;
            }

            // Pause courte entre les batchs pour éviter la surcharge
            if ($index < count($batches) - 1) {
                usleep(100000); // 100ms
            }
        }

        return [
            'success' => true,
            'message' => 'Batch notifications sent',
            'success_count' => $totalSuccess,
            'failure_count' => $totalFailure,
            'total_batches' => count($batches),
        ];
    }

    /**
     * Envoie une notification à un topic
     *
     * @param string $topic
     * @param string $title
     * @param string $body
     * @param array $data
     * @return array
     */
    public function sendToTopic(string $topic, string $title, string $body, array $data = []): array
    {
        if (!$this->messaging) {
            return [
                'success' => false,
                'message' => 'Firebase Messaging not initialized',
            ];
        }

        try {
            $message = CloudMessage::withTarget('topic', $topic)
                ->withNotification(Notification::create($title, $body))
                ->withData($data)
                ->withAndroidConfig(
                    AndroidConfig::fromArray([
                        'priority' => 'high',
                        'notification' => [
                            'sound' => 'default',
                            'channel_id' => 'high_importance_channel',
                        ],
                    ])
                )
                ->withApnsConfig(
                    ApnsConfig::fromArray([
                        'headers' => [
                            'apns-priority' => '10',
                        ],
                        'payload' => [
                            'aps' => [
                                'sound' => 'default',
                                'badge' => 1,
                            ],
                        ],
                    ])
                );

            $this->messaging->send($message);

            Log::info("Notification sent to topic: {$topic}");

            return [
                'success' => true,
                'message' => 'Notification sent to topic successfully',
            ];
        } catch (Exception $e) {
            Log::error('Error sending notification to topic: ' . $e->getMessage());

            return [
                'success' => false,
                'message' => 'Failed to send notification to topic',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Envoie une notification à plusieurs tokens
     *
     * @param array $tokens
     * @param string $title
     * @param string $body
     * @param array $data
     * @return array
     */
    public function sendToTokens(array $tokens, string $title, string $body, array $data = []): array
    {
        $data = self::stringifyData($data);
        if (!$this->messaging) {
            return [
                'success' => false,
                'message' => 'Firebase Messaging not initialized',
            ];
        }

        if (empty($tokens)) {
            return [
                'success' => false,
                'message' => 'No tokens provided',
            ];
        }

        try {
            $message = CloudMessage::new()
                ->withNotification(Notification::create($title, $body))
                ->withData($data)
                ->withAndroidConfig(
                    AndroidConfig::fromArray([
                        'priority' => 'high',
                        'notification' => [
                            'sound' => 'default',
                            'channel_id' => 'high_importance_channel',
                            'color' => '#FF6B35',
                        ],
                    ])
                )
                ->withApnsConfig(
                    ApnsConfig::fromArray([
                        'headers' => [
                            'apns-priority' => '10',
                        ],
                        'payload' => [
                            'aps' => [
                                'sound' => 'default',
                                'badge' => 1,
                                'alert' => [
                                    'title' => $title,
                                    'body' => $body,
                                ],
                            ],
                        ],
                    ])
                );

            $report = $this->messaging->sendMulticast($message, $tokens);

            $successCount = $report->successes()->count();
            $failureCount = $report->failures()->count();

            // Désactiver les tokens invalides
            if ($failureCount > 0) {
                foreach ($report->failures()->getItems() as $failure) {
                    $invalidToken = $failure->target()->value();

                    // Marquer le token comme inactif
                    DeviceToken::where('token', $invalidToken)
                        ->update(['is_active' => false]);

                    Log::warning("Invalid FCM token deactivated: {$invalidToken}");
                }
            }

            Log::info("Notification sent: {$successCount} succeeded, {$failureCount} failed");

            return [
                'success' => true,
                'message' => 'Notifications sent',
                'success_count' => $successCount,
                'failure_count' => $failureCount,
            ];
        } catch (Exception $e) {
            Log::error('Error sending notifications: ' . $e->getMessage());

            return [
                'success' => false,
                'message' => 'Failed to send notifications',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Abonne des tokens à un topic
     *
     * @param array $tokens
     * @param string $topic
     * @return array
     */
    public function subscribeToTopic(array $tokens, string $topic): array
    {
        if (!$this->messaging) {
            return [
                'success' => false,
                'message' => 'Firebase Messaging not initialized',
            ];
        }

        try {
            $this->messaging->subscribeToTopic($topic, $tokens);

            Log::info("Tokens subscribed to topic: {$topic}");

            return [
                'success' => true,
                'message' => 'Tokens subscribed to topic successfully',
            ];
        } catch (Exception $e) {
            Log::error('Error subscribing to topic: ' . $e->getMessage());

            return [
                'success' => false,
                'message' => 'Failed to subscribe to topic',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Désabonne des tokens d'un topic
     *
     * @param array $tokens
     * @param string $topic
     * @return array
     */
    public function unsubscribeFromTopic(array $tokens, string $topic): array
    {
        if (!$this->messaging) {
            return [
                'success' => false,
                'message' => 'Firebase Messaging not initialized',
            ];
        }

        try {
            $this->messaging->unsubscribeFromTopic($topic, $tokens);

            Log::info("Tokens unsubscribed from topic: {$topic}");

            return [
                'success' => true,
                'message' => 'Tokens unsubscribed from topic successfully',
            ];
        } catch (Exception $e) {
            Log::error('Error unsubscribing from topic: ' . $e->getMessage());

            return [
                'success' => false,
                'message' => 'Failed to unsubscribe from topic',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Enregistre une notification dans la base de données
     *
     * @param int $userId
     * @param string $title
     * @param string $body
     * @param array $data
     * @return void
     */
    private function saveNotificationToDatabase(int $userId, string $title, string $body, array $data = []): ?int
    {
        try {
            $notification = NotificationModel::create([
                'user_id' => $userId,
                'title' => $title,
                'body' => $body,
                'type' => $data['type'] ?? null,
                'data' => $data,
                'is_read' => false,
                'sent_at' => now(),
            ]);

            Log::info("Notification saved to database for user {$userId}");
            return $notification->id;
        } catch (Exception $e) {
            Log::error("Error saving notification to database: {$e->getMessage()}");
            return null;
        }
    }

}
