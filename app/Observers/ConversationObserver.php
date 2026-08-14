<?php

namespace App\Observers;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;

class ConversationObserver
{
    /**
     * Handle the Conversation "created" event.
     */
    public function created(Conversation $conversation): void
    {
        // Récupérer l'utilisateur système
        $systemUser = User::where('email', 'system@asso.app')->first();

        if (!$systemUser) {
            \Log::warning('⚠️ Utilisateur système introuvable - Impossible d\'envoyer le message de sécurité');
            return;
        }

        // Message de sécurité à envoyer automatiquement
        $securityMessage = "Vous prenez un risque en envoyant votre numéro de téléphone / e-mail\n\n" .
            "Ici nous garantissons la sécurité de vos échanges, c'est pourquoi nous vous conseillons de " .
            "rester sur la messagerie pour discuter et passer par le paiement sécurisé pour vos transactions.\n\n" .
            "→ En savoir plus";

        // Créer le message système
        Message::create([
            'conversation_id' => $conversation->id,
            'sender_id' => $systemUser->id,
            'message' => $securityMessage,
            'is_system' => true,
            'is_read' => false,
        ]);

        \Log::info("✅ Message de sécurité envoyé pour la conversation #{$conversation->id}");
    }

    /**
     * Handle the Conversation "updated" event.
     */
    public function updated(Conversation $conversation): void
    {
        //
    }

    /**
     * Handle the Conversation "deleted" event.
     */
    public function deleted(Conversation $conversation): void
    {
        //
    }

    /**
     * Handle the Conversation "restored" event.
     */
    public function restored(Conversation $conversation): void
    {
        //
    }

    /**
     * Handle the Conversation "force deleted" event.
     */
    public function forceDeleted(Conversation $conversation): void
    {
        //
    }
}
