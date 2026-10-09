<?php

namespace App\Observers;

use App\Models\DeviceToken;
use App\Services\FirebaseMessagingService;
use Illuminate\Support\Facades\Log;

/**
 * Abonnements aux topics des annonces : c'est l'application qui s'abonne
 * (all_users pour les anciennes versions, all_users_<langue> pour les
 * nouvelles, qui quittent all_users). Le serveur ne réabonne plus : il
 * remettrait une nouvelle version sur all_users et l'annonce arriverait deux
 * fois. Il désabonne seulement les tokens désactivés ou supprimés.
 */
class DeviceTokenObserver
{
    public function updated(DeviceToken $deviceToken): void
    {
        if (!$deviceToken->is_active && $deviceToken->isDirty('is_active')) {
            $this->unsubscribe($deviceToken, 'désactivation');
        }
    }

    public function deleted(DeviceToken $deviceToken): void
    {
        $this->unsubscribe($deviceToken, 'suppression');
    }

    private function unsubscribe(DeviceToken $deviceToken, string $reason): void
    {
        try {
            $fcmService = new FirebaseMessagingService();
            foreach (FirebaseMessagingService::announcementTopics() as $topic) {
                $fcmService->unsubscribeFromTopic([$deviceToken->token], $topic);
            }
            Log::info("Token {$deviceToken->id} désabonné des topics d'annonces après {$reason}");
        } catch (\Exception $e) {
            Log::error("Erreur lors du désabonnement du token {$deviceToken->id}: " . $e->getMessage());
        }
    }
}
