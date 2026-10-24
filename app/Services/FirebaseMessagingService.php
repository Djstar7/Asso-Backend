<?php

namespace App\Services;

use App\Http\Middleware\SetLocale;
use App\Models\DeviceToken;
use App\Models\Notification as NotificationModel;
use App\Models\User;
use App\Support\Translation\LocalizedText;
use Exception;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Factory;
use Kreait\Firebase\Messaging\AndroidConfig;
use Kreait\Firebase\Messaging\ApnsConfig;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification;

class FirebaseMessagingService
{
    protected $messaging;

    /** Topic des annonces des anciennes versions de l'app (français). */
    public const TOPIC_ALL = 'all_users';

    /** Topic des annonces dans une langue (all_users_fr, all_users_en). */
    public static function localeTopic(string $locale): string
    {
        return self::TOPIC_ALL.'_'.$locale;
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
        // Les fixtures publiées par PHPUnit ne doivent jamais diffuser sur les vrais topics.
        if (app()->runningUnitTests()) {
            return;
        }
        try {
            // Chemin vers le fichier de configuration Firebase
            $serviceAccountPath = storage_path('app/private/firebase/service-account.json');

            if (! file_exists($serviceAccountPath)) {
                Log::warning('Firebase service account file not found at: '.$serviceAccountPath);

                return;
            }

            $factory = (new Factory)
                ->withServiceAccount($serviceAccountPath);

            $this->messaging = $factory->createMessaging();
        } catch (Exception $e) {
            Log::error('Firebase Messaging initialization error: '.$e->getMessage());
        }
    }

    /**
     * Envoie une notification à un utilisateur spécifique
     *
     * Un titre ou un texte LocalizedText est enregistré avec sa clé : l'historique
     * le rédige à nouveau dans la langue courante de l'utilisateur.
     */
    public function sendToUser(User $user, string|LocalizedText $title, string|LocalizedText $body, array $data = [], ?string $platform = null): array
    {
        return app(NotificationDispatchService::class)->send($user, $title, $body, $data, $platform);
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
     */
    public function sendToUsers(array $userIds, string $title, string $body, array $data = []): array
    {
        $results = [];
        foreach (User::whereIn('id', array_unique($userIds))->get() as $user) {
            $results[] = $this->sendToUser($user, $title, $body, $data);
        }

        return ['success' => collect($results)->contains(fn ($r) => $r['success'] ?? false),
            'results' => $results];
    }

    /**
     * Envoie une notification à tous les utilisateurs
     * Utilise le système de topics pour les envois en masse
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
            Log::info('Sending notification batch '.($index + 1).'/'.count($batches));

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
     */
    public function sendToTopic(string $topic, string $title, string $body, array $data = []): array
    {
        if (! $this->messaging) {
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
            Log::error('Error sending notification to topic: '.$e->getMessage());

            return [
                'success' => false,
                'message' => 'Failed to send notification to topic',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Envoie une notification à plusieurs tokens
     */
    public function sendToTokens(array $tokens, string $title, string $body, array $data = []): array
    {
        if (! $this->messaging) {
            return ['success' => false, 'success_count' => 0, 'retryable' => true, 'error_code' => 'NOT_CONFIGURED'];
        }
        if (! $tokens) {
            return ['success' => false, 'success_count' => 0, 'retryable' => false, 'error_code' => 'NO_DEVICE'];
        }
        try {
            [$android, $apns] = $this->deliveryConfiguration($data);
            $message = CloudMessage::new()->withNotification(Notification::create($title, $body))
                ->withData(self::stringifyData($data))->withAndroidConfig(AndroidConfig::fromArray($android))
                ->withApnsConfig(ApnsConfig::fromArray($apns));
            $report = $this->messaging->sendMulticast($message, array_values(array_unique($tokens)));
            $successes = $report->successes()->count();
            $policy = ['error_code' => null, 'retryable' => false];
            foreach ($report->failures()->getItems() as $failure) {
                $failurePolicy = FcmFailurePolicy::classify($failure->error());
                $policy['error_code'] ??= $failurePolicy['error_code'];
                $policy['retryable'] = $policy['retryable'] || $failurePolicy['retryable'];
                if ($failurePolicy['invalid_token']) {
                    DeviceToken::where('token', $failure->target()->value())->update(['is_active' => false]);
                }
            }

            return ['success' => $successes > 0, 'success_count' => $successes,
                'failure_count' => $report->failures()->count()] + $policy;
        } catch (\Throwable $e) {
            $policy = FcmFailurePolicy::classify($e);
            Log::warning('Firebase push request failed', ['error_code' => $policy['error_code']]);

            return ['success' => false, 'success_count' => 0] + $policy;
        }
    }

    public function deliveryConfiguration(array $data): array
    {
        $category = $data['category'] ?? NotificationPreferences::category($data['type'] ?? 'system');
        $quiet = in_array($category, ['marketing', 'real_estate'], true);
        $identity = (string) ($data['notification_id'] ?? $data['notification_key'] ?? hash('sha256', json_encode($data)));
        $notification = ['channel_id' => $quiet ? 'asso_updates_v1' : 'asso_actions_v1',
            'icon' => 'ic_stat_asso', 'color' => '#FF6B35', 'tag' => 'asso_'.hash('sha256', $identity)];
        if (! $quiet) {
            $notification['sound'] = 'default';
        }
        $aps = ['thread-id' => 'asso_'.$category];
        if (! $quiet) {
            $aps['sound'] = 'default';
        }
        if (isset($data['badge_count'])) {
            $aps['badge'] = max(0, (int) $data['badge_count']);
        }

        return [['priority' => $quiet ? 'normal' : 'high', 'notification' => $notification],
            ['headers' => ['apns-push-type' => 'alert', 'apns-priority' => $quiet ? '5' : '10',
                'apns-collapse-id' => hash('sha256', $identity)], 'payload' => ['aps' => $aps]]];
    }

    /**
     * Abonne des tokens à un topic
     */
    public function subscribeToTopic(array $tokens, string $topic): array
    {
        if (! $this->messaging) {
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
            Log::error('Error subscribing to topic: '.$e->getMessage());

            return [
                'success' => false,
                'message' => 'Failed to subscribe to topic',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Désabonne des tokens d'un topic
     */
    public function unsubscribeFromTopic(array $tokens, string $topic): array
    {
        if (! $this->messaging) {
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
            Log::error('Error unsubscribing from topic: '.$e->getMessage());

            return [
                'success' => false,
                'message' => 'Failed to unsubscribe from topic',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Enregistre une notification dans la base de données
     */
    private function saveNotificationToDatabase(int $userId, string|LocalizedText $title, string|LocalizedText $body, array $data = []): ?int
    {
        try {
            // Les reprises immobilières réutilisent l'entrée d'historique du même lot.
            if (! empty($data['notification_key'])) {
                $existing = NotificationModel::where('user_id', $userId)
                    ->where('data->notification_key', $data['notification_key'])->first();
                if ($existing) {
                    return $existing->id;
                }
            }
            $notification = NotificationModel::create([
                'user_id' => $userId,
                'title' => (string) $title,
                'body' => (string) $body,
                'type' => $data['type'] ?? null,
                'data' => NotificationModel::withI18n($data, $title, $body),
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
